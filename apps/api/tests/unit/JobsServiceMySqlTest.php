<?php
declare(strict_types=1);

use App\Modules\Identity\Domain\ActorContext;
use App\Modules\Jobs\Application\JobsService;
use App\Modules\Jobs\Application\WorkerService;
use App\Modules\Jobs\Domain\CommandContext;
use App\Modules\Jobs\Domain\CommandValidator;
use App\Modules\Jobs\Domain\ExpectedStatus;
use App\Modules\Jobs\Domain\ExternalReceipt;
use App\Modules\Jobs\Domain\StatusObservation;
use App\Modules\Jobs\Domain\VmCommand;
use App\Modules\Integrations\GatewayResult;
use App\Modules\Integrations\GatewayDisposition;
use App\Modules\Integrations\Http\SimulatorHttpGateway;
use App\Modules\Integrations\Http\SimulatorHttpStatusReader;
use App\Modules\Integrations\VmCommandGateway;
use App\Modules\Integrations\VmStatusReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Portal\Shared\AuditAppender;
use Portal\Shared\AuditSink;
use Portal\Shared\CanonicalJson;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Portal\Shared\SystemClock;

final class JobsServiceMySqlTest extends TestCase
{
    private BaseConnection $db;
    private Clock $clock;
    private string $testDatabase;
    private ?int $cleanupActorId = null;
    private ?string $cleanupRequestId = null;
    /** @var list<string> */
    private array $cleanupEventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $runtime = Runtime::config();
        $this->testDatabase = (string) ($runtime['test_db'] ?? '');
        self::assertNotSame('', $this->testDatabase, 'The isolated MySQL test database must be configured.');

        $this->db = Database::connect([
            'DSN' => '',
            'hostname' => '127.0.0.1',
            'username' => (string) ($runtime['test_user'] ?? ''),
            'password' => (string) ($runtime['test_password'] ?? ''),
            'database' => $this->testDatabase,
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => false,
            'charset' => 'utf8mb4',
            'DBCollat' => 'utf8mb4_0900_ai_ci',
            'port' => (int) ($runtime['mysql_port'] ?? 3306),
            'dateFormat' => [
                'date' => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s.u',
                'time' => 'H:i:s',
            ],
        ], false);

        $this->db->initialize();
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 3');
        foreach (['users', 'vm_profiles', 'vms', 'jobs', 'idempotency_requests', 'quota_accounts', 'quota_reservations', 'job_attempts', 'outbox', 'audit_events'] as $table) {
            self::assertTrue($this->db->tableExists($table), 'The P2 MySQL schema must be applied before this test.');
        }
        $this->clock = new SystemClock();
        $this->cleanupEventIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->cleanupActorId !== null && $this->cleanupRequestId !== null) {
            $this->removeOnlyThisTestRequest();
        }

        $this->db->close();
        parent::tearDown();
    }

    public function testSubmitAtomicallyCreatesRequestAndReplayDoesNotConsumeQuotaAgain(): void
    {
        $memberA = $this->userId('portal-member-a');
        $actor = $this->actor($memberA, 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($memberA);
        $oldLimit = $quotaBefore['limit_count'];
        $inUseBefore = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($memberA, $inUseBefore + 1);
        $key = $this->key();

        try {
            $service = $this->service();
            $accepted = $service->submit($actor, ['profile_id' => 'sim-basic-1'], $key);
            $created = $this->service()->getVm($actor, $accepted['vm_id']);

            self::assertSame('queued', $accepted['status']);
            self::assertSame('simulated', $accepted['mode']);
            self::assertSame('provisioning', $created['lifecycle_state']);
            self::assertSame($memberA, $created['owner_user_id']);
            self::assertSame(['vcpus', 'memory_mb', 'disk_gb'], array_keys($created['profile']));
            self::assertArrayNotHasKey('network_ref', $created['profile']);
            self::assertSame('queued', $created['latest_job']['state']);

            $outbox = $this->db->table('outbox')->where('job_id', $accepted['job_id'])->get()->getRowArray();
            self::assertIsArray($outbox);
            self::assertSame('queued', $outbox['state']);
            $command = json_decode((string) $outbox['command_payload'], true, 64, JSON_THROW_ON_ERROR);
            $attempt = $this->db->table('job_attempts')->where('job_id', $accepted['job_id'])->get()->getRowArray();
            self::assertIsArray($attempt);
            $normalizedCommand = (new CommandValidator())->validate($command, new CommandContext(
                $actor->requestId,
                $accepted['job_id'],
                $accepted['vm_id'],
                1,
                (string) $command['external_idempotency_key'],
                $command['profile'],
                'simulated',
            ));
            self::assertSame('create', $normalizedCommand->operation);
            self::assertSame('queued', $this->service()->listJobs($actor)[0]['state']);

            $idem = $this->db->table('idempotency_requests')->where('actor_id', $memberA)->where('job_id', $accepted['job_id'])->get()->getRowArray();
            self::assertIsArray($idem);
            self::assertSame(CanonicalJson::hash(['profile_id' => 'sim-basic-1']), $idem['body_fingerprint']);
            self::assertSame(hash('sha256', (string) $command['external_idempotency_key']), $attempt['external_key_ref']);

            $this->db->table('quota_accounts')->where('actor_id', $memberA)->update(['limit_count' => $inUseBefore + 1]);
            $replayActor = $this->actor($memberA, 'member');
            $replayed = $service->submit($replayActor, ['profile_id' => 'sim-basic-1'], $key);

            self::assertSame($accepted['job_id'], $replayed['job_id']);
            self::assertSame($accepted['vm_id'], $replayed['vm_id']);
            self::assertSame('queued', $replayed['status']);
            self::assertSame($replayActor->requestId, $replayed['request_id']);
            self::assertSame(1, $this->countWhere('jobs', ['id' => $accepted['job_id']]));
            self::assertSame(1, $this->countWhere('vms', ['id' => $accepted['vm_id']]));
            self::assertSame(1, $this->countWhere('outbox', ['job_id' => $accepted['job_id']]));
            self::assertSame(1, $this->countWhere('quota_reservations', ['job_id' => $accepted['job_id']]));
            self::assertSame($inUseBefore + 1, (int) $this->quota($memberA)['reserved_count'] + (int) $this->quota($memberA)['consumed_count']);
            self::assertSame(1, $this->countWhere('audit_events', ['job_id' => $accepted['job_id'], 'decision' => 'allowed']));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $memberA)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testDifferentBodyWithSameKeyConflictsWithoutCreatingAnotherRequest(): void
    {
        $actor = $this->actor($this->userId('portal-member-a'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $key = $this->key();
        $service = $this->service();
        try {
            $accepted = $service->submit($actor, ['profile_id' => 'sim-basic-1'], $key);

            $changedActor = $this->actor($actor->userId, 'member');
            try {
                $service->submit($changedActor, ['profile_id' => 'different-profile'], $key);
                self::fail('A changed body under the same key must conflict before profile lookup.');
            } catch (PortalException $exception) {
                self::assertSame('idempotency_conflict', $exception->errorCode);
                self::assertSame(409, $exception->httpStatus);
            }

            self::assertSame(1, $this->countWhere('jobs', ['actor_id' => $actor->userId, 'request_id' => $actor->requestId]));
            self::assertSame(1, $this->countWhere('idempotency_requests', ['job_id' => $accepted['job_id']]));
            self::assertSame(1, $this->countWhere('audit_events', ['job_id' => $accepted['job_id'], 'decision' => 'allowed']));
            self::assertSame(1, $this->countWhere('audit_events', ['request_id' => $changedActor->requestId, 'decision' => 'denied']));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testQuotaExceededLeavesNoPartialBusinessRecordsAndAuditsDenial(): void
    {
        $memberB = $this->userId('portal-member-b');
        $actor = $this->actor($memberB, 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($memberB);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $ownerVmCountBefore = $this->baselineVmCount($memberB);
        $jobCountBefore = $this->db->table('jobs')->countAllResults();
        $attemptCountBefore = $this->db->table('job_attempts')->countAllResults();
        $outboxCountBefore = $this->db->table('outbox')->countAllResults();
        $reservationCountBefore = $this->db->table('quota_reservations')->countAllResults();
        $this->setQuotaLimit($memberB, $inUse);
        $key = $this->key();
        $keyDigest = CanonicalJson::hash(['idempotency_key' => $key]);

        try {
            try {
                $this->service()->submit($actor, ['profile_id' => 'sim-basic-1'], $key);
                self::fail('A new request at the quota boundary must be rejected.');
            } catch (PortalException $exception) {
                self::assertSame('quota_exceeded', $exception->errorCode);
                self::assertSame(409, $exception->httpStatus);
            }

            self::assertSame(0, $this->countWhere('jobs', ['request_id' => $actor->requestId]));
            self::assertSame(0, $this->countWhere('idempotency_requests', ['actor_id' => $memberB, 'operation' => 'create', 'key_digest' => $keyDigest]));
            self::assertSame($ownerVmCountBefore, $this->baselineVmCount($memberB));
            self::assertSame($jobCountBefore, $this->db->table('jobs')->countAllResults());
            self::assertSame($attemptCountBefore, $this->db->table('job_attempts')->countAllResults());
            self::assertSame($outboxCountBefore, $this->db->table('outbox')->countAllResults());
            self::assertSame($reservationCountBefore, $this->db->table('quota_reservations')->countAllResults());
            self::assertSame($inUse, (int) $this->quota($memberB)['reserved_count'] + (int) $this->quota($memberB)['consumed_count']);
            self::assertSame(1, $this->countWhere('audit_events', ['request_id' => $actor->requestId, 'decision' => 'denied']));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $memberB)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testAuditFailureRollsBackQuotaVmJobAttemptOutboxAndIdempotencyTogether(): void
    {
        $memberB = $this->userId('portal-member-b');
        $actor = $this->actor($memberB, 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($memberB);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $ownerVmCountBefore = $this->baselineVmCount($memberB);
        $this->setQuotaLimit($memberB, $inUse + 1);
        $key = $this->key();
        $keyDigest = CanonicalJson::hash(['idempotency_key' => $key]);
        $jobCountBefore = $this->db->table('jobs')->countAllResults();
        $vmCountBefore = $this->db->table('vms')->countAllResults();
        $attemptCountBefore = $this->db->table('job_attempts')->countAllResults();
        $outboxCountBefore = $this->db->table('outbox')->countAllResults();
        $reservationCountBefore = $this->db->table('quota_reservations')->countAllResults();

        try {
            try {
                $this->service(new ThrowingJobsAuditSink())->submit($actor, ['profile_id' => 'sim-basic-1'], $key);
                self::fail('An audit append failure must fail the request.');
            } catch (PortalException $exception) {
                self::assertSame('request_unavailable', $exception->errorCode);
                self::assertSame(503, $exception->httpStatus);
            }

            self::assertSame(0, $this->countWhere('idempotency_requests', ['actor_id' => $memberB, 'operation' => 'create', 'key_digest' => $keyDigest]));
            self::assertSame(0, $this->countWhere('jobs', ['request_id' => $actor->requestId]));
            self::assertSame($ownerVmCountBefore, $this->baselineVmCount($memberB));
            self::assertSame($jobCountBefore, $this->db->table('jobs')->countAllResults());
            self::assertSame($vmCountBefore, $this->db->table('vms')->countAllResults());
            self::assertSame($attemptCountBefore, $this->db->table('job_attempts')->countAllResults());
            self::assertSame($outboxCountBefore, $this->db->table('outbox')->countAllResults());
            self::assertSame($reservationCountBefore, $this->db->table('quota_reservations')->countAllResults());
            self::assertSame($inUse, (int) $this->quota($memberB)['reserved_count'] + (int) $this->quota($memberB)['consumed_count']);
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $memberB)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testInvalidBodyAndUnavailableProfileAreRejectedBeforeQuotaReservation(): void
    {
        $memberB = $this->userId('portal-member-b');
        $quotaBefore = $this->quota($memberB);
        $vmCountBefore = $this->db->table('vms')->countAllResults();
        $jobCountBefore = $this->db->table('jobs')->countAllResults();

        $invalidActor = $this->actor($memberB, 'member');
        $this->track($invalidActor);
        try {
            $this->service()->submit($invalidActor, ['profile_id' => 'sim-basic-1', 'owner_user_id' => $this->userId('portal-admin')], $this->key());
            self::fail('Client supplied specification fields must be rejected.');
        } catch (PortalException $exception) {
            self::assertSame('INVALID_CONTRACT', $exception->errorCode);
            self::assertSame(400, $exception->httpStatus);
        }

        $unknownActor = $this->actor($memberB, 'member');
        try {
            $this->service()->submit($unknownActor, ['profile_id' => 'not-enabled'], $this->key());
            self::fail('A profile not enabled in the server catalogue must be rejected.');
        } catch (PortalException $exception) {
            self::assertSame('profile_not_available', $exception->errorCode);
            self::assertSame(409, $exception->httpStatus);
        }

        self::assertSame($vmCountBefore, $this->db->table('vms')->countAllResults());
        self::assertSame($jobCountBefore, $this->db->table('jobs')->countAllResults());
        self::assertSame((int) $quotaBefore['reserved_count'], (int) $this->quota($memberB)['reserved_count']);
    }

    public function testConcurrentDifferentKeysCannotExceedTheActorQuota(): void
    {
        $memberB = $this->userId('portal-member-b');
        $quotaBefore = $this->quota($memberB);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($memberB, $inUse + 1);
        $jobsBefore = $this->db->table('jobs')->countAllResults();
        $vmsBefore = $this->db->table('vms')->countAllResults();
        $reservationsBefore = $this->db->table('quota_reservations')->countAllResults();

        $actors = [$this->actor($memberB, 'member'), $this->actor($memberB, 'member')];
        $keys = [$this->key(), $this->key()];
        $barrier = tempnam(sys_get_temp_dir(), 'jobs-quota-');
        self::assertNotFalse($barrier);
        @unlink($barrier);
        $ready = [$barrier . '.a.ready', $barrier . '.b.ready'];
        $go = $barrier . '.go';
        $script = __DIR__ . '/../_support/jobs_submit_worker.php';
        $processes = [];

        try {
            foreach ([0, 1] as $index) {
                $command = [PHP_BINARY, $script, (string) $memberB, $actors[$index]->requestId, $keys[$index], $ready[$index], $go];
                $pipes = [];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
                self::assertIsResource($process, 'A MySQL concurrency worker must start.');
                fclose($pipes[0]);
                $processes[$index] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $deadline = microtime(true) + 12.0;
            while ((! is_file($ready[0]) || ! is_file($ready[1])) && microtime(true) < $deadline) {
                usleep(1000);
            }
            self::assertFileExists($ready[0]);
            self::assertFileExists($ready[1]);
            file_put_contents($go, 'go');

            $results = [];
            foreach ($processes as $index => $worker) {
                $output = stream_get_contents($worker['stdout']);
                $errorOutput = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                self::assertSame('', trim($errorOutput), 'Worker stderr must not contain raw diagnostics.');
                self::assertSame(0, $exitCode, 'The request worker must return a controlled result.');
                $result = json_decode((string) $output, true, 16, JSON_THROW_ON_ERROR);
                $result['worker_index'] = $index;
                $results[] = $result;
            }

            $accepted = array_values(array_filter($results, static fn (array $result): bool => $result['result'] === 'accepted'));
            $rejected = array_values(array_filter($results, static fn (array $result): bool => $result['result'] === 'rejected'));
            self::assertCount(1, $accepted);
            self::assertCount(1, $rejected);
            self::assertSame('quota_exceeded', $rejected[0]['code'], json_encode($results, JSON_THROW_ON_ERROR));
            $this->track($actors[$accepted[0]['worker_index']]);

            self::assertSame($jobsBefore + 1, $this->db->table('jobs')->countAllResults());
            self::assertSame($vmsBefore + 1, $this->db->table('vms')->countAllResults());
            self::assertSame($reservationsBefore + 1, $this->db->table('quota_reservations')->countAllResults());
            self::assertSame($inUse + 1, (int) $this->quota($memberB)['reserved_count'] + (int) $this->quota($memberB)['consumed_count']);
            self::assertSame(1, $this->countWhere('audit_events', ['job_id' => $accepted[0]['job_id'], 'decision' => 'allowed']));
            self::assertSame(1, $this->countWhere('audit_events', ['request_id' => $actors[$rejected[0]['worker_index']]->requestId, 'decision' => 'denied']));
        } finally {
            if (! is_file($go)) {
                file_put_contents($go, 'stop');
            }
            foreach ($processes as $worker) {
                if (is_resource($worker['process'])) {
                    foreach (['stdout', 'stderr'] as $pipe) {
                        if (is_resource($worker[$pipe])) {
                            fclose($worker[$pipe]);
                        }
                    }
                    @proc_terminate($worker['process']);
                    @proc_close($worker['process']);
                }
            }
            foreach (array_merge($ready, [$go]) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $this->db->table('quota_accounts')->where('actor_id', $memberB)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testWorkerDispatchesAndCompletionConsumesTheReservationExactlyOnce(): void
    {
        $actor = $this->actor($this->userId('portal-member-a'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $reservedBefore = (int) $quotaBefore['reserved_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:00:00Z'));
        $this->clock = $clock;
        $eventId = Portal\Shared\Identifiers::new('evt');
        $terminalConflictId = Portal\Shared\Identifiers::new('evt');
        $this->trackEvent($eventId);
        $this->trackEvent($terminalConflictId);

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $worker = $this->worker($gateway, new JobsFakeStatusReader(), $clock);
            self::assertSame(['claimed' => 1, 'sent' => 1, 'unknown' => 0, 'rejected' => 0], $worker->tick('worker-fixture-01'));
            self::assertCount(1, $gateway->commands);

            $command = $gateway->commands[0];
            $accepted = $worker->receiveCompletion($this->completion($command, $eventId), 'req_callback_fixture_01');
            self::assertSame('accepted', $accepted['disposition']);
            self::assertSame('duplicate', $worker->receiveCompletion($this->completion($command, $eventId), 'req_callback_fixture_02')['disposition']);
            self::assertSame(['processed' => 1, 'deferred' => 0, 'duplicates' => 0, 'quarantined' => 0], $worker->processInbox());

            $changed = $this->completion($command, $eventId, 'failed');
            self::assertSame('content_conflict', $worker->receiveCompletion($changed, 'req_callback_fixture_03')['disposition']);
            self::assertSame('accepted', $worker->receiveCompletion($this->completion($command, $terminalConflictId, 'failed'), 'req_callback_fixture_04')['disposition']);
            self::assertSame(['processed' => 0, 'deferred' => 0, 'duplicates' => 0, 'quarantined' => 1], $worker->processInbox());

            $jobRow = $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRowArray();
            $vmRow = $this->db->table('vms')->where('id', $job['vm_id'])->get()->getRowArray();
            $attemptRow = $this->db->table('job_attempts')->where('job_id', $job['job_id'])->where('attempt_no', 1)->get()->getRowArray();
            $reservation = $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRowArray();
            $outbox = $this->db->table('outbox')->where('job_id', $job['job_id'])->get()->getRowArray();
            self::assertSame('succeeded', $jobRow['state']);
            self::assertSame('active', $vmRow['lifecycle_state']);
            self::assertNull($vmRow['observed_power_state']);
            self::assertSame('succeeded', $attemptRow['state']);
            self::assertSame('consumed', $reservation['state']);
            self::assertSame('sent', $outbox['state']);
            self::assertSame('quarantined', $this->db->table('callback_inbox')->where('event_id', $terminalConflictId)->get()->getRow('state'));
            self::assertSame('terminal_conflict', $this->db->table('callback_inbox')->where('event_id', $terminalConflictId)->get()->getRow('quarantine_reason'));
            self::assertSame($reservedBefore, (int) $this->quota($actor->userId)['reserved_count']);
            self::assertSame((int) $quotaBefore['consumed_count'] + 1, (int) $this->quota($actor->userId)['consumed_count']);
            self::assertSame(1, $this->countWhere('callback_conflicts', ['event_id' => $eventId, 'reason' => 'event_content_conflict']));
            self::assertSame(1, $this->countWhere('audit_events', ['job_id' => $job['job_id'], 'action' => 'vm.create.completed']));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testUnknownReceiptHoldsQuotaAndOnlyRetriesTheSameExternalKey(): void
    {
        $actor = $this->actor($this->userId('portal-member-b'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:10:00Z'));
        $this->clock = $clock;

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::unknown('TRANSPORT_TIMEOUT'),
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $worker = $this->worker($gateway, new JobsFakeStatusReader(), $clock);
            self::assertSame(['claimed' => 1, 'sent' => 0, 'unknown' => 1, 'rejected' => 0], $worker->tick('worker-fixture-02'));
            self::assertSame('reconciliation_required', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('unknown', $this->db->table('outbox')->where('job_id', $job['job_id'])->get()->getRow('state'));
            self::assertSame($inUse + 1, (int) $this->quota($actor->userId)['reserved_count'] + (int) $this->quota($actor->userId)['consumed_count']);

            self::assertSame(0, $worker->tick('worker-fixture-02')['claimed']);
            $clock->advanceSeconds(21);
            self::assertSame(['claimed' => 1, 'sent' => 1, 'unknown' => 0, 'rejected' => 0], $worker->tick('worker-fixture-02'));
            self::assertCount(2, $gateway->commands);
            self::assertSame($gateway->commands[0]->externalIdempotencyKey, $gateway->commands[1]->externalIdempotencyKey);
            self::assertSame('running', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('reserved', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testCompletionBeforeReceiptIsDeferredThenAppliedAfterMapping(): void
    {
        $actor = $this->actor($this->userId('portal-member-a'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:20:00Z'));
        $this->clock = $clock;
        $eventId = Portal\Shared\Identifiers::new('evt');
        $this->trackEvent($eventId);

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $worker = $this->worker($gateway, new JobsFakeStatusReader(), $clock);
            $gateway->duringSubmit = function (VmCommand $command) use ($worker, $eventId): void {
                $ack = $worker->receiveCompletion($this->completion($command, $eventId), 'req_callback_early_01');
                self::assertSame('accepted', $ack['disposition']);
                self::assertSame('deferred', $this->db->table('callback_inbox')->where('event_id', $eventId)->get()->getRow('state'));
                self::assertSame('dispatching', $this->db->table('jobs')->where('id', $command->jobId)->get()->getRow('state'));
            };

            $worker->tick('worker-fixture-03');
            self::assertSame('succeeded', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('processed', $this->db->table('callback_inbox')->where('event_id', $eventId)->get()->getRow('state'));
            self::assertSame('consumed', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testStaleAttemptCallbackIsQuarantinedWithoutChangingTheCurrentJob(): void
    {
        $actor = $this->actor($this->userId('portal-member-b'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:30:00Z'));
        $this->clock = $clock;
        $eventId = Portal\Shared\Identifiers::new('evt');
        $this->trackEvent($eventId);

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $worker = $this->worker($gateway, new JobsFakeStatusReader(), $clock);
            $worker->tick('worker-fixture-04');

            $this->db->table('jobs')->where('id', $job['job_id'])->update(['current_attempt_no' => 2, 'state' => 'running']);
            $this->db->table('job_attempts')->insert([
                'id' => Portal\Shared\Identifiers::new('att'),
                'job_id' => $job['job_id'],
                'attempt_no' => 2,
                'state' => 'running',
                'external_job_id' => 'external_job_future',
                'external_vm_id' => 'external_vm_future',
                'external_key_ref' => hash('sha256', 'future-external-key'),
                'started_at' => $clock->utcNow()->format('Y-m-d H:i:s.u'),
                'finished_at' => null,
                'observed_at' => null,
                'error_code' => null,
            ]);

            $stale = $this->completion($gateway->commands[0], $eventId);
            self::assertSame('accepted', $worker->receiveCompletion($stale, 'req_callback_stale_01')['disposition']);
            self::assertSame(['processed' => 0, 'deferred' => 0, 'duplicates' => 0, 'quarantined' => 1], $worker->processInbox());
            self::assertSame('running', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame(2, (int) $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('current_attempt_no'));
            self::assertSame('quarantined', $this->db->table('callback_inbox')->where('event_id', $eventId)->get()->getRow('state'));
            self::assertSame('stale_attempt', $this->db->table('callback_inbox')->where('event_id', $eventId)->get()->getRow('quarantine_reason'));
            self::assertSame('reserved', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testExpiredRunningJobRequiresReconciliationAndObservedSuccessDoesNotFinalizeIt(): void
    {
        $actor = $this->actor($this->userId('portal-member-a'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:40:00Z'));
        $this->clock = $clock;

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $reader = new JobsFakeStatusReader(new StatusObservation(
                'external_job_' . substr(hash('sha256', $job['job_id']), 0, 16),
                'external_vm_' . substr(hash('sha256', $job['vm_id']), 0, 16),
                'succeeded',
                $clock->utcNow()->modify('+21 seconds'),
                'simulated',
                'running',
                'not_ready',
                null,
            ));
            $worker = $this->worker($gateway, $reader, $clock);
            $worker->tick('worker-fixture-05');
            $clock->advanceSeconds(21);
            self::assertSame(0, $worker->tick('worker-fixture-05')['claimed']);

            $observed = $worker->observeJob($actor, $job['job_id']);
            self::assertSame('fresh', $observed['freshness']);
            self::assertSame('completion_unconfirmed', $observed['discrepancy']);
            self::assertSame('succeeded', $observed['observation']['status']);
            self::assertSame('reconciliation_required', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('reserved', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('running', $this->db->table('vms')->where('id', $job['vm_id'])->get()->getRow('observed_power_state'));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testMemberQueriesAreOwnerScopedWhileAdminCanReadAll(): void
    {
        $memberA = $this->userId('portal-member-a');
        $memberB = $this->userId('portal-member-b');
        $actorA = $this->actor($memberA, 'member');
        $this->track($actorA);
        $quotaBefore = $this->quota($memberA);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($memberA, $inUse + 1);

        try {
            $service = $this->service();
            $actorB = $this->actor($memberB, 'member');
            $otherMemberVmIds = array_column($service->listVms($actorB), 'vm_id');
            $otherMemberJobIds = array_column($service->listJobs($actorB), 'job_id');
            $response = $service->submit($actorA, ['profile_id' => 'sim-basic-1'], $this->key());

            self::assertSame($response['vm_id'], $service->listVms($actorA)[0]['vm_id']);
            self::assertSame($memberA, $service->listVms($actorA)[0]['owner_user_id']);
            self::assertSame($response['job_id'], $service->listJobs($actorA)[0]['job_id']);
            self::assertSame($otherMemberVmIds, array_column($service->listVms($actorB), 'vm_id'));
            self::assertSame($otherMemberJobIds, array_column($service->listJobs($actorB), 'job_id'));
            foreach ($service->listVms($actorB) as $otherMemberVm) {
                self::assertSame($memberB, $otherMemberVm['owner_user_id']);
            }
            try {
                $service->getVm($actorB, $response['vm_id']);
                self::fail('A member must not read another member VM.');
            } catch (PortalException $exception) {
                self::assertSame('resource_not_found', $exception->errorCode);
                self::assertSame(404, $exception->httpStatus);
            }
            try {
                $service->getJob($actorB, $response['job_id']);
                self::fail('A member must not read another member job.');
            } catch (PortalException $exception) {
                self::assertSame('resource_not_found', $exception->errorCode);
            }

            $admin = $this->actor($this->userId('portal-admin'), 'admin');
            $adminVm = $service->getVm($admin, $response['vm_id']);
            self::assertSame($response['vm_id'], $adminVm['vm_id']);
            self::assertSame($memberA, $adminVm['owner_user_id']);
            self::assertSame($memberA, $service->listVms($admin)[0]['owner_user_id']);
            self::assertSame($response['job_id'], $service->getJob($admin, $response['job_id'])['job_id']);
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $memberA)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testDefiniteGatewayRejectionFailsTheJobAndReleasesQuotaOnce(): void
    {
        $actor = $this->actor($this->userId('portal-member-b'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:50:00Z'));
        $this->clock = $clock;

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $worker = $this->worker(new JobsScriptedGateway([GatewayResult::rejected('PROFILE_UNAVAILABLE')]), new JobsFakeStatusReader(), $clock);
            self::assertSame(['claimed' => 1, 'sent' => 0, 'unknown' => 0, 'rejected' => 1], $worker->tick('worker-fixture-06'));
            self::assertSame('failed', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('failed', $this->db->table('job_attempts')->where('job_id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('failed', $this->db->table('vms')->where('id', $job['vm_id'])->get()->getRow('lifecycle_state'));
            self::assertSame('released', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
            self::assertSame($inUse, (int) $this->quota($actor->userId)['reserved_count'] + (int) $this->quota($actor->userId)['consumed_count']);
            self::assertSame('sent', $this->db->table('outbox')->where('job_id', $job['job_id'])->get()->getRow('state'));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testFailedCompletionReleasesTheReservationWithTheTerminalAudit(): void
    {
        $actor = $this->actor($this->userId('portal-member-b'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:52:00Z'));
        $this->clock = $clock;
        $eventId = Portal\Shared\Identifiers::new('evt');
        $this->trackEvent($eventId);

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $worker = $this->worker($gateway, new JobsFakeStatusReader(), $clock);
            $worker->tick('worker-fixture-failed-completion');
            self::assertSame('accepted', $worker->receiveCompletion($this->completion($gateway->commands[0], $eventId, 'failed'), 'req_callback_failed_01')['disposition']);
            self::assertSame(['processed' => 1, 'deferred' => 0, 'duplicates' => 0, 'quarantined' => 0], $worker->processInbox());

            self::assertSame('failed', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('failed', $this->db->table('vms')->where('id', $job['vm_id'])->get()->getRow('lifecycle_state'));
            self::assertSame('released', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
            self::assertSame($inUse, (int) $this->quota($actor->userId)['reserved_count'] + (int) $this->quota($actor->userId)['consumed_count']);
            self::assertSame(1, $this->countWhere('audit_events', ['job_id' => $job['job_id'], 'action' => 'vm.create.failed']));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testLeaseGenerationFencesAnOldGatewayResponse(): void
    {
        $actor = $this->actor($this->userId('portal-member-a'), 'member');
        $this->track($actor);
        $quotaBefore = $this->quota($actor->userId);
        $oldLimit = $quotaBefore['limit_count'];
        $inUse = (int) $quotaBefore['reserved_count'] + (int) $quotaBefore['consumed_count'];
        $this->setQuotaLimit($actor->userId, $inUse + 1);
        $clock = new JobsTestClock(new DateTimeImmutable('2026-10-01T03:55:00Z'));
        $this->clock = $clock;

        try {
            $job = $this->service(null, $clock)->submit($actor, ['profile_id' => 'sim-basic-1'], $this->key());
            $gateway = new JobsScriptedGateway([
                GatewayResult::accepted($this->receiptForJob($job['job_id'], $job['vm_id'], $clock->utcNow())),
            ]);
            $gateway->duringSubmit = function (VmCommand $command) use ($clock): void {
                $outbox = $this->db->table('outbox')->where('job_id', $command->jobId)->get()->getRowArray();
                self::assertSame('leased', $outbox['state']);
                $this->db->table('outbox')->where('id', $outbox['id'])->update([
                    'lease_owner' => 'replacement-worker',
                    'lease_token' => str_repeat('f', 64),
                    'lease_generation' => (int) $outbox['lease_generation'] + 1,
                    'lease_expires_at' => $clock->utcNow()->modify('+10 minutes')->format('Y-m-d H:i:s.u'),
                ]);
            };
            $worker = $this->worker($gateway, new JobsFakeStatusReader(), $clock);
            self::assertSame(['claimed' => 1, 'sent' => 0, 'unknown' => 1, 'rejected' => 0], $worker->tick('worker-fixture-07'));
            self::assertSame('dispatching', $this->db->table('jobs')->where('id', $job['job_id'])->get()->getRow('state'));
            self::assertSame('leased', $this->db->table('outbox')->where('job_id', $job['job_id'])->get()->getRow('state'));
            self::assertSame(2, (int) $this->db->table('outbox')->where('job_id', $job['job_id'])->get()->getRow('lease_generation'));
            self::assertNull($this->db->table('job_attempts')->where('job_id', $job['job_id'])->get()->getRow('external_job_id'));
            self::assertSame('reserved', $this->db->table('quota_reservations')->where('job_id', $job['job_id'])->get()->getRow('state'));
        } finally {
            $this->db->table('quota_accounts')->where('actor_id', $actor->userId)->update(['limit_count' => $oldLimit]);
        }
    }

    public function testSimulatorHttpAdaptersUseTheWireContractAndLocalCredential(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, 'A loopback port is required for the HTTP contract fixture.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        $port = (int) substr(strrchr($address, ':'), 1);
        $baseUrl = 'http://127.0.0.1:' . $port;
        $router = __DIR__ . '/../_support/simulator_http_stub.php';
        $pipes = [];
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($server, 'The loopback contract fixture must start.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $jobId = Portal\Shared\Identifiers::new('job');
        $vmId = Portal\Shared\Identifiers::new('vm');
        $command = new VmCommand(
            'v1', Portal\Shared\Identifiers::new('req'), $jobId, $vmId, 'create', 1,
            Portal\Shared\Identifiers::new('ext'),
            ['profile_id' => 'sim-basic-1', 'profile_version' => 'v1', 'snapshot' => ['vcpus' => 1, 'memory_mb' => 1024, 'disk_gb' => 20, 'network_ref' => 'simulated-only']],
            'simulated',
        );
        [$externalJobId, $externalVmId] = $this->externalIds($jobId, $vmId);
        $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-sim-http-' . hash('sha256', $externalJobId) . '.json';

        try {
            $deadline = microtime(true) + 8.0;
            $ready = false;
            while (! $ready && microtime(true) < $deadline) {
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $probeErrno, $probeError, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                } else {
                    usleep(10000);
                }
            }
            self::assertTrue($ready, 'The loopback HTTP fixture must accept connections.');

            $gateway = new SimulatorHttpGateway($baseUrl, 'fixture-simulator-secret');
            $dispatch = $gateway->submit($command);
            self::assertSame(GatewayDisposition::ACCEPTED, $dispatch->disposition);
            self::assertSame($externalJobId, $dispatch->receipt?->externalJobId);
            self::assertSame($externalVmId, $dispatch->receipt?->externalVmId);

            $observation = (new SimulatorHttpStatusReader($baseUrl, 'fixture-simulator-secret'))
                ->readJob(new ExpectedStatus($externalJobId, $externalVmId, 'simulated'));
            self::assertSame('succeeded', $observation->status);
            self::assertSame('running', $observation->powerState);
            self::assertSame('not_ready', $observation->guestReadiness);

            $unauthorized = (new SimulatorHttpGateway($baseUrl, 'wrong-fixture-secret'))->submit($command);
            self::assertSame(GatewayDisposition::REJECTED, $unauthorized->disposition);
            self::assertSame('SIMULATOR_AUTH_REQUIRED', $unauthorized->errorCode);
        } finally {
            @proc_terminate($server);
            foreach ([1, 2] as $pipe) {
                if (is_resource($pipes[$pipe])) {
                    stream_get_contents($pipes[$pipe]);
                    fclose($pipes[$pipe]);
                }
            }
            @proc_close($server);
            if (is_file($statePath)) {
                @unlink($statePath);
            }
        }
    }

    private function service(?AuditSink $audit = null, ?Clock $clock = null): JobsService
    {
        $clock ??= $this->clock;
        return new JobsService($this->db, $audit ?? new AuditAppender($this->db, $clock), new JobsTestLogger(), $clock);
    }

    private function worker(VmCommandGateway $gateway, VmStatusReader $reader, ?Clock $clock = null): WorkerService
    {
        $clock ??= $this->clock;
        return new WorkerService($this->db, new AuditAppender($this->db, $clock), new JobsTestLogger(), $clock, $gateway, $reader);
    }

    /** @return array<string, mixed> */
    private function completion(VmCommand $command, string $eventId, string $status = 'succeeded'): array
    {
        [$externalJobId, $externalVmId] = $this->externalIds($command->jobId, $command->vmId);
        return [
            'event_id' => $eventId,
            'job_id' => $command->jobId,
            'external_job_id' => $externalJobId,
            'external_vm_id' => $externalVmId,
            'attempt' => $command->attempt,
            'status' => $status,
            'observed_at' => '2026-10-01T03:00:00Z',
            'error_code' => $status === 'failed' ? 'SIM_EXECUTION_FAILED' : null,
            'mode' => 'simulated',
        ];
    }

    private function receiptForJob(string $jobId, string $vmId, DateTimeImmutable $observedAt): ExternalReceipt
    {
        [$externalJobId, $externalVmId] = $this->externalIds($jobId, $vmId);
        return new ExternalReceipt($externalJobId, $externalVmId, 'accepted', $observedAt, 'simulated');
    }

    /** @return array{string,string} */
    private function externalIds(string $jobId, string $vmId): array
    {
        return [
            'external_job_' . substr(hash('sha256', $jobId), 0, 16),
            'external_vm_' . substr(hash('sha256', $vmId), 0, 16),
        ];
    }

    private function userId(string $username): int
    {
        $row = $this->db->table('users')->select('id')->where('username', $username)->where('deleted_at', null)->get()->getRowArray();
        self::assertIsArray($row, 'The synthetic test account must be seeded.');
        return (int) $row['id'];
    }

    private function actor(int $userId, string $role): ActorContext
    {
        return new ActorContext($userId, [$role], 'user', 'req_' . bin2hex(random_bytes(16)), 'simulated');
    }

    private function key(): string
    {
        return 'idem_' . bin2hex(random_bytes(20));
    }

    private function track(ActorContext $actor): void
    {
        $this->cleanupActorId = $actor->userId;
        $this->cleanupRequestId = $actor->requestId;
    }

    private function trackEvent(string $eventId): void
    {
        $this->cleanupEventIds[] = $eventId;
    }

    /** @return array<string, mixed> */
    private function quota(int $actorId): array
    {
        $row = $this->db->table('quota_accounts')->where('actor_id', $actorId)->get()->getRowArray();
        self::assertIsArray($row, 'The synthetic test actor needs an initialized quota row.');
        return $row;
    }

    private function setQuotaLimit(int $actorId, int $limit): void
    {
        self::assertSame($this->testDatabase, $this->db->getDatabase());
        self::assertGreaterThanOrEqual(0, $limit);
        $this->db->table('quota_accounts')->where('actor_id', $actorId)->update(['limit_count' => $limit]);
    }

    private function countWhere(string $table, array $where): int
    {
        $query = $this->db->table($table);
        foreach ($where as $key => $value) {
            if (str_ends_with((string) $key, ' >=')) {
                $query->where(substr((string) $key, 0, -3) . ' >=', $value, false);
            } else {
                $query->where($key, $value);
            }
        }
        return $query->countAllResults();
    }

    private function baselineVmCount(int $actorId): int
    {
        return (int) $this->db->table('vms')->where('owner_user_id', $actorId)->where('mode', 'simulated')->countAllResults();
    }

    private function removeOnlyThisTestRequest(): void
    {
        if ($this->db->getDatabase() !== $this->testDatabase) {
            throw new RuntimeException('Refusing cleanup outside the isolated test database.');
        }

        $jobs = $this->db->table('jobs')->select(['id', 'vm_id'])->where('request_id', $this->cleanupRequestId)->where('actor_id', $this->cleanupActorId)->get()->getResultArray();
        foreach ($jobs as $job) {
            $jobId = (string) $job['id'];
            $reservations = $this->db->table('quota_reservations')->select(['id', 'state'])->where('job_id', $jobId)->where('actor_id', $this->cleanupActorId)->get()->getResultArray();
            $reservedCount = count(array_filter($reservations, static fn (array $reservation): bool => $reservation['state'] === 'reserved'));
            $consumedCount = count(array_filter($reservations, static fn (array $reservation): bool => $reservation['state'] === 'consumed'));
            $this->db->transBegin();
            if ($this->cleanupEventIds !== []) {
                $this->db->table('callback_inbox')->whereIn('event_id', $this->cleanupEventIds)->delete();
                $this->db->table('callback_conflicts')->whereIn('event_id', $this->cleanupEventIds)->delete();
            }
            $this->db->table('idempotency_requests')->where('actor_id', $this->cleanupActorId)->where('job_id', $jobId)->delete();
            $this->db->table('outbox')->where('job_id', $jobId)->delete();
            $this->db->table('quota_reservations')->where('job_id', $jobId)->where('actor_id', $this->cleanupActorId)->delete();
            $this->db->table('job_attempts')->where('job_id', $jobId)->delete();
            $this->db->table('jobs')->where('id', $jobId)->where('actor_id', $this->cleanupActorId)->delete();
            $this->db->table('vms')->where('id', (string) $job['vm_id'])->where('owner_user_id', $this->cleanupActorId)->delete();
            if ($reservedCount > 0 || $consumedCount > 0) {
                $this->db->query(
                    'UPDATE `quota_accounts` SET `reserved_count`=GREATEST(`reserved_count`-?,0),`consumed_count`=GREATEST(`consumed_count`-?,0) WHERE `actor_id`=?',
                    [$reservedCount, $consumedCount, $this->cleanupActorId],
                );
            }
            $this->db->transCommit();
        }

        if ($this->cleanupEventIds !== [] && $jobs === []) {
            $this->db->transBegin();
            $this->db->table('callback_inbox')->whereIn('event_id', $this->cleanupEventIds)->delete();
            $this->db->table('callback_conflicts')->whereIn('event_id', $this->cleanupEventIds)->delete();
            $this->db->transCommit();
        }
    }
}

final class JobsTestLogger implements EventLogger
{
    /** @var list<array{event:string,context:array<string,mixed>}> */
    public array $events = [];

    public function write(string $event, array $context = []): bool
    {
        $this->events[] = ['event' => $event, 'context' => $context];
        return true;
    }
}

final class ThrowingJobsAuditSink implements AuditSink
{
    public function append(array $event): string
    {
        throw new RuntimeException('Synthetic audit failure.');
    }
}

final class JobsTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function utcNow(): DateTimeImmutable
    {
        return $this->now->setTimezone(new DateTimeZone('UTC'));
    }

    public function monotonic(): float
    {
        return microtime(true);
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}

final class JobsScriptedGateway implements VmCommandGateway
{
    /** @var list<GatewayResult> */
    private array $results;
    /** @var list<VmCommand> */
    public array $commands = [];
    /** @var (\Closure(VmCommand):void)|null */
    public ?\Closure $duringSubmit = null;

    /** @param list<GatewayResult> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function submit(VmCommand $command): GatewayResult
    {
        $this->commands[] = $command;
        if ($this->duringSubmit !== null) {
            ($this->duringSubmit)($command);
        }
        return array_shift($this->results) ?? GatewayResult::unknown('SCRIPTED_RESULT_EXHAUSTED');
    }
}

final class JobsFakeStatusReader implements VmStatusReader
{
    public function __construct(private readonly ?StatusObservation $observation = null, private readonly ?PortalException $error = null)
    {
    }

    public function readJob(ExpectedStatus $target): StatusObservation
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        if ($this->observation === null) {
            throw new PortalException('status_unavailable', 'The scripted status is unavailable.', 503);
        }
        return $this->observation;
    }
}
