<?php
declare(strict_types=1);

use App\Modules\Simulator\Application\SimulatorService;
use App\Modules\Simulator\Domain\SimulationCommandValidator;
use App\Modules\Simulator\Domain\SimulationProfileValidator;
use App\Commands\SimulatorCliPolicy;
use CodeIgniter\Database\BaseConnection;
use Portal\Shared\CanonicalJson;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use PHPUnit\Framework\TestCase;

/** @internal Uses only the configured simulator test database. */
final class SimulatorServiceMySqlTest extends TestCase
{
    private BaseConnection $db;

    /** @var array<string, mixed> */
    private array $runtime;

    /** @var list<array{job_id:string,vm_id:string}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = Runtime::config();
        $this->db = self::connectTestDatabase($this->runtime);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $entry) {
            $simJobIds = self::rows($this->db, 'SELECT `id` FROM `sim_jobs` WHERE `api_job_id` = ?', [$entry['job_id']]);
            foreach ($simJobIds as $simJob) {
                $this->db->table('sim_deliveries')->where('sim_job_id', $simJob['id'])->delete();
            }
            $this->db->table('sim_vms')->where('api_vm_id', $entry['vm_id'])->delete();
            $this->db->table('sim_jobs')->where('api_job_id', $entry['job_id'])->delete();
        }
        $this->db->close();
        parent::tearDown();
    }

    public function testSameKeyReplaysOnePersistedDrawAndChangedBodyConflicts(): void
    {
        $clock = new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $service = new SimulatorService($this->db, $clock, new CaptureLogger(), static fn (array $payload): int => 202);
        $command = $this->commandFor('success');
        $first = $service->submit($command);
        $replay = $service->submit($command);

        $this->assertSame($first, $replay);
        $this->assertSame(1, (int) self::scalar($this->db, 'SELECT COUNT(*) FROM `sim_jobs` WHERE `api_job_id` = ?', [$command['job_id']]));
        $this->assertSame(1, (int) self::scalar($this->db, 'SELECT COUNT(*) FROM `sim_vms` WHERE `api_vm_id` = ?', [$command['vm_id']]));

        $changed = $command;
        $changed['profile']['snapshot']['disk_gb']++;
        try {
            $service->submit($changed);
            $this->fail('Changed command body reused the persisted idempotency key.');
        } catch (PortalException $exception) {
            $this->assertSame('SIMULATOR_IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }

        $changedKey = $command;
        $changedKey['external_idempotency_key'] = 'key_' . bin2hex(random_bytes(16));
        try {
            $service->submit($changedKey);
            $this->fail('A second simulator job was created for the same API job attempt.');
        } catch (PortalException $exception) {
            $this->assertSame('SIMULATOR_ATTEMPT_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }
        $this->assertSame(1, (int) self::scalar($this->db, 'SELECT COUNT(*) FROM `sim_jobs` WHERE `api_job_id` = ?', [$command['job_id']]));
    }

    public function testAllFiveScenariosPersistAndDispatchAsDefined(): void
    {
        $config = $this->simulationProfile();
        $clock = new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z'));

        foreach (['success', 'failure', 'late', 'missing', 'duplicate'] as $scenario) {
            $command = $this->commandFor($scenario, $config);
            $sent = [];
            $service = new SimulatorService($this->db, $clock, new CaptureLogger(), function (array $payload) use (&$sent): int {
                $sent[] = $payload;
                return 202;
            });
            $receipt = $service->submit($command);
            $this->assertSame('accepted', $receipt['status']);
            $job = self::row($this->db, 'SELECT * FROM `sim_jobs` WHERE `id` = ?', [$receipt['external_job_id']]);
            $this->assertSame($scenario, $job['selected_scenario']);
            $deliveries = self::rows($this->db, 'SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ? ORDER BY `sequence_no`', [$receipt['external_job_id']]);

            if ($scenario === 'missing') {
                $this->assertCount(1, $deliveries);
                $this->assertSame('missing', $deliveries[0]['delivery_kind']);
                $clock->set(new DateTimeImmutable((string) $job['due_at'], new DateTimeZone('UTC')));
                $summary = $service->tick(5, 10);
                $this->assertSame(0, $summary['sent']);
                $this->assertSame(1, $summary['suppressed']);
                $this->assertCount(0, $sent);
                $this->assertSame('succeeded', self::scalar($this->db, 'SELECT `state` FROM `sim_jobs` WHERE `id` = ?', [$receipt['external_job_id']]));
                continue;
            }

            if ($scenario === 'duplicate') {
                $this->assertCount(2, $deliveries);
                $this->assertSame('completion', $deliveries[0]['delivery_kind']);
                $this->assertSame('completion', $deliveries[1]['delivery_kind']);
                $this->assertSame($deliveries[0]['event_id'], $deliveries[1]['event_id']);
                $this->assertSame($deliveries[0]['payload_fingerprint'], $deliveries[1]['payload_fingerprint']);
            } else {
                $this->assertCount(1, $deliveries);
                $this->assertSame('completion', $deliveries[0]['delivery_kind']);
            }

            $dueAt = new DateTimeImmutable((string) $job['due_at'], new DateTimeZone('UTC'));
            if ($scenario === 'late') {
                $normalBoundary = $clock->utcNow()->add(new DateInterval('PT' . $config['delay_max_seconds'] . 'S'));
                $clock->set($normalBoundary);
                $beforeDue = $service->tick(5, 10);
                $this->assertSame(0, $beforeDue['claimed']);
                $this->assertCount(0, $sent);
            }
            $clock->set($scenario === 'duplicate' ? $dueAt->add(new DateInterval('PT' . $config['duplicate_delay_seconds'] . 'S')) : $dueAt);
            $summary = $service->tick(5, 10);
            $expectedSends = $scenario === 'duplicate' ? 2 : 1;
            $this->assertSame($expectedSends, $summary['sent']);
            $this->assertCount($expectedSends, $sent);
            $sentFields = array_keys($sent[0]);
            sort($sentFields);
            $this->assertSame(['attempt','error_code','event_id','external_job_id','external_vm_id','job_id','mode','observed_at','status'], $sentFields);
            $this->assertSame($command['job_id'], $sent[0]['job_id']);
            $this->assertSame($receipt['external_job_id'], $sent[0]['external_job_id']);
            $this->assertSame('simulated', $sent[0]['mode']);
            $this->assertSame($dueAt->format('Y-m-d\TH:i:s.u\Z'), $sent[0]['observed_at']);
            if ($scenario === 'failure') {
                $this->assertSame('failed', $sent[0]['status']);
                $this->assertContains($sent[0]['error_code'], $config['error_codes']);
            } else {
                $this->assertSame('succeeded', $sent[0]['status']);
                $this->assertNull($sent[0]['error_code']);
            }
            if ($scenario === 'duplicate') {
                $this->assertSame($sent[0], $sent[1]);
            }

            $expectedJobStatus = $scenario === 'failure' ? 'failed' : 'succeeded';
            $this->assertSame($expectedJobStatus, self::scalar($this->db, 'SELECT `state` FROM `sim_jobs` WHERE `id` = ?', [$receipt['external_job_id']]));
            $this->assertSame($expectedJobStatus === 'succeeded' ? 'ready' : 'absent', self::scalar($this->db, 'SELECT `state` FROM `sim_vms` WHERE `api_vm_id` = ?', [$command['vm_id']]));
        }
    }

    public function testDispatcherDoesNotHoldTransactionDuringCallbackAndFencesExpiredLeaseAck(): void
    {
        $config = $this->simulationProfile();
        $command = $this->commandFor('success', $config);
        $clock = new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $receipt = (new SimulatorService($this->db, $clock, new CaptureLogger(), static fn (array $payload): int => 202))->submit($command);
        $job = self::row($this->db, 'SELECT `due_at` FROM `sim_jobs` WHERE `id` = ?', [$receipt['external_job_id']]);
        $clock->set(new DateTimeImmutable((string) $job['due_at'], new DateTimeZone('UTC')));

        $secondSummary = null;
        $secondService = new SimulatorService($this->db, $clock, new CaptureLogger(), function (array $payload): int {
            $this->assertSame(0, $this->db->transDepth, 'A callback was invoked while a database transaction remained open.');
            return 202;
        });
        $firstService = new SimulatorService($this->db, $clock, new CaptureLogger(), function (array $payload) use ($clock, $secondService, &$secondSummary): int {
            $this->assertSame(0, $this->db->transDepth, 'A callback was invoked while a database transaction remained open.');
            $clock->advanceSeconds(3);
            $secondSummary = $secondService->tick(1, 2);
            return 202;
        });

        $firstSummary = $firstService->tick(1, 2);
        $this->assertSame(1, $firstSummary['fenced']);
        $this->assertSame(1, $secondSummary['sent']);
        $delivery = self::row($this->db, 'SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ?', [$receipt['external_job_id']]);
        $this->assertSame('delivered', $delivery['state']);
        $this->assertSame(2, (int) $delivery['delivery_generation']);
        $this->assertSame(1, (int) $delivery['send_count']);
    }

    public function testCallbackConflictIsQuarantinedAndNotRetried(): void
    {
        $config = $this->simulationProfile();
        $command = $this->commandFor('success', $config);
        $clock = new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $service = new SimulatorService($this->db, $clock, new CaptureLogger(), static fn (array $payload): int => 409);
        $receipt = $service->submit($command);
        $job = self::row($this->db, 'SELECT `due_at` FROM `sim_jobs` WHERE `id` = ?', [$receipt['external_job_id']]);
        $clock->set(new DateTimeImmutable((string) $job['due_at'], new DateTimeZone('UTC')));

        $first = $service->tick(1, 10);
        $second = $service->tick(1, 10);
        $delivery = self::row($this->db, 'SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ?', [$receipt['external_job_id']]);
        $this->assertSame(1, $first['quarantined']);
        $this->assertSame(0, $second['claimed']);
        $this->assertSame('quarantined', $delivery['state']);
        $this->assertSame(1, (int) $delivery['send_count']);
    }

    public function testMissingCallbackRecoveryReusesTheOriginalCanonicalEventAndIsIdempotent(): void
    {
        $profile = $this->simulationProfile();
        $command = $this->commandFor('missing', $profile);
        $clock = new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $sent = [];
        $service = new SimulatorService($this->db, $clock, new CaptureLogger(), function (array $payload) use (&$sent): int {
            $this->assertSame(0, $this->db->transDepth, 'A callback was invoked during the reservation transaction.');
            $sent[] = $payload;
            return 202;
        });

        $receipt = $service->submit($command);
        $jobId = $receipt['external_job_id'];
        $jobBeforeRecovery = self::row($this->db, 'SELECT * FROM `sim_jobs` WHERE `id` = ?', [$jobId]);
        $original = self::row($this->db, "SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ? AND `delivery_kind` = 'missing' AND `sequence_no` = 1", [$jobId]);
        $originalPayload = json_decode((string) $original['payload'], true, 32, JSON_THROW_ON_ERROR);
        $clock->set(new DateTimeImmutable((string) $jobBeforeRecovery['due_at'], new DateTimeZone('UTC')));

        $suppressed = $service->tick(5, 10);
        $this->assertSame(1, $suppressed['suppressed']);
        $this->assertCount(0, $sent);
        $this->assertSame('suppressed', self::scalar($this->db, 'SELECT `state` FROM `sim_deliveries` WHERE `id` = ?', [$original['id']]));

        $first = $service->scheduleMissingCallbackResend($jobId);
        $recovery = self::row($this->db, "SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ? AND `delivery_kind` = 'recovery' AND `sequence_no` = 1", [$jobId]);
        $recoveryPayload = json_decode((string) $recovery['payload'], true, 32, JSON_THROW_ON_ERROR);
        $this->assertFalse($first['replayed']);
        $this->assertSame($original['event_id'], $recovery['event_id']);
        $this->assertSame($original['payload_fingerprint'], $recovery['payload_fingerprint']);
        $this->assertSame($originalPayload, $recoveryPayload);
        $this->assertSame('pending', $recovery['state']);

        $second = $service->scheduleMissingCallbackResend($jobId);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['delivery_id'], $second['delivery_id']);
        $this->assertSame(1, (int) self::scalar($this->db, "SELECT COUNT(*) FROM `sim_deliveries` WHERE `sim_job_id` = ? AND `delivery_kind` = 'recovery'", [$jobId]));

        $beforeSend = self::row($this->db, 'SELECT `state`,`selected_scenario`,`command_fingerprint`,`due_at` FROM `sim_jobs` WHERE `id` = ?', [$jobId]);
        $result = $service->tick(5, 10);
        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $sent);
        $this->assertSame(CanonicalJson::encode($originalPayload), CanonicalJson::encode($sent[0]));
        $this->assertSame($original['event_id'], $sent[0]['event_id']);
        $afterSend = self::row($this->db, 'SELECT `state`,`selected_scenario`,`command_fingerprint`,`due_at` FROM `sim_jobs` WHERE `id` = ?', [$jobId]);
        $this->assertSame($beforeSend, $afterSend);

        $third = $service->scheduleMissingCallbackResend($jobId);
        $this->assertTrue($third['replayed']);
        $this->assertSame('delivered', $third['state']);
        $this->assertSame(1, (int) self::scalar($this->db, 'SELECT COUNT(*) FROM `sim_jobs` WHERE `api_job_id` = ?', [$command['job_id']]));
        $this->assertSame('succeeded', self::scalar($this->db, 'SELECT `state` FROM `sim_jobs` WHERE `id` = ?', [$jobId]));
    }

    public function testMissingCallbackRecoveryRejectsWrongScenarioAndNotYetSuppressedState(): void
    {
        $profile = $this->simulationProfile();
        $clock = new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $service = new SimulatorService($this->db, $clock, new CaptureLogger());

        $successCommand = $this->commandFor('success', $profile);
        $success = $service->submit($successCommand);
        try {
            $service->scheduleMissingCallbackResend($success['external_job_id']);
            $this->fail('A non-missing scenario was accepted for missing-callback recovery.');
        } catch (PortalException $exception) {
            $this->assertSame('SIMULATOR_RECOVERY_NOT_APPLICABLE', $exception->errorCode);
        }

        $missingCommand = $this->commandFor('missing', $profile);
        $missing = $service->submit($missingCommand);
        try {
            $service->scheduleMissingCallbackResend($missing['external_job_id']);
            $this->fail('A missing callback was recovered before its original delivery was suppressed.');
        } catch (PortalException $exception) {
            $this->assertSame('SIMULATOR_RECOVERY_NOT_APPLICABLE', $exception->errorCode);
        }

        $this->assertSame(0, (int) self::scalar($this->db, "SELECT COUNT(*) FROM `sim_deliveries` WHERE `delivery_kind` = 'recovery' AND `sim_job_id` IN (?,?)", [$success['external_job_id'], $missing['external_job_id']]));
    }

    public function testRecoveryCommandPolicyRequiresCliDevelopmentAndSimulatedMode(): void
    {
        $this->assertTrue(SimulatorCliPolicy::allows('cli', 'development', 'simulated'));
        $this->assertFalse(SimulatorCliPolicy::allows('cli-server', 'development', 'simulated'));
        $this->assertFalse(SimulatorCliPolicy::allows('cli', 'testing', 'simulated'));
        $this->assertFalse(SimulatorCliPolicy::allows('cli', 'development', 'real'));
    }

    public function testScenarioOverrideIsNotAvailableToHttpOrNonDevelopmentCallers(): void
    {
        $command = $this->commandFor('success');
        $service = new SimulatorService($this->db, new MutableSimulatorClock(new DateTimeImmutable('2026-10-01T00:00:00Z')), new CaptureLogger());
        try {
            $service->submit($command, 'success');
            $this->fail('Forced scenarios were accepted outside the development CLI.');
        } catch (PortalException $exception) {
            $this->assertSame('FORCED_SCENARIO_FORBIDDEN', $exception->errorCode);
            $this->assertSame(403, $exception->httpStatus);
        }
        $this->assertSame(0, (int) self::scalar($this->db, 'SELECT COUNT(*) FROM `sim_jobs` WHERE `api_job_id` = ?', [$command['job_id']]));
    }

    public function testCommandValidatorRejectsUnknownFieldsAndNonSimulatedMode(): void
    {
        $command = $this->commandFor('success');
        $validator = new SimulationCommandValidator();
        $withExtra = $command;
        $withExtra['force_scenario'] = 'success';
        try {
            $validator->validate($withExtra);
            $this->fail('Unknown force_scenario request field was accepted.');
        } catch (PortalException $exception) {
            $this->assertSame('INVALID_COMMAND', $exception->errorCode);
        }

        $command['mode'] = 'real';
        try {
            $validator->validate($command);
            $this->fail('A real mode command was accepted by the simulator.');
        } catch (PortalException $exception) {
            $this->assertSame('INVALID_COMMAND', $exception->errorCode);
        }
    }

    /** @param array<string, mixed>|null $runtimeProfile
     *  @return array<string, mixed>
     */
    private function commandFor(string $scenario, ?array $runtimeProfile = null): array
    {
        $profile = $runtimeProfile ?? $this->simulationProfile();
        $jobId = self::jobIdForScenario($scenario, $profile);
        $vmId = 'vmt_' . bin2hex(random_bytes(12));
        $this->created[] = ['job_id' => $jobId, 'vm_id' => $vmId];
        return [
            'version' => 'v1',
            'request_id' => 'req_' . bin2hex(random_bytes(12)),
            'job_id' => $jobId,
            'vm_id' => $vmId,
            'operation' => 'create',
            'attempt' => 1,
            'external_idempotency_key' => 'key_' . bin2hex(random_bytes(16)),
            'profile' => [
                'profile_id' => 'sim-basic-1',
                'profile_version' => 'v1',
                'snapshot' => ['vcpus' => 1, 'memory_mb' => 1024, 'disk_gb' => 20, 'network_ref' => 'simulated-only'],
            ],
            'mode' => 'simulated',
        ];
    }

    /** @return array<string, mixed> */
    private function simulationProfile(): array
    {
        $config = Runtime::config();
        return $config['simulation'];
    }

    /** @param array<string, mixed> $profile */
    private static function jobIdForScenario(string $expected, array $profile): string
    {
        $validated = (new SimulationProfileValidator())->validate($profile, (int) $profile['maximum_pending']);
        for ($index = 0; $index < 10000; $index++) {
            $jobId = 'job_p3_' . dechex($index);
            $digest = hash('sha256', $jobId . $validated->values['seed'] . $validated->values['profile_version']);
            $slot = hexdec(substr($digest, 0, 8)) % 100;
            $cursor = 0;
            foreach (['success', 'failure', 'late', 'missing', 'duplicate'] as $scenario) {
                $cursor += $validated->values['weights'][$scenario];
                if ($slot < $cursor) {
                    if ($scenario === $expected) {
                        return $jobId;
                    }
                    break;
                }
            }
        }
        throw new RuntimeException('Could not select a deterministic job ID for a weighted scenario.');
    }

    private static function connectTestDatabase(array $runtime): BaseConnection
    {
        $database = (string) $runtime['sim_test_db'];
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $database) !== 1) {
            throw new RuntimeException('Configured simulator test database name is invalid.');
        }
        try {
            $db = Config\Database::connect([
                'DSN' => '',
                'hostname' => '127.0.0.1',
                'username' => (string) $runtime['sim_test_user'],
                'password' => (string) $runtime['sim_test_password'],
                'database' => $database,
                'DBDriver' => 'MySQLi',
                'DBPrefix' => '',
                'pConnect' => false,
                'DBDebug' => false,
                'charset' => 'utf8mb4',
                'DBCollat' => 'utf8mb4_unicode_ci',
                'strictOn' => true,
                'port' => (int) $runtime['mysql_port'],
            ], false);
            $db->initialize();
            if ($db->query('SELECT DATABASE()') === false) {
                throw new RuntimeException('connection unavailable');
            }
            return $db;
        } catch (Throwable) {
            throw new RuntimeException('Configured simulator test database is unavailable; diagnostics suppressed.');
        }
    }

    private static function scalar(BaseConnection $db, string $sql, array $binds = []): mixed
    {
        $row = self::row($db, $sql, $binds);
        return $row === null ? null : array_values($row)[0];
    }

    private static function row(BaseConnection $db, string $sql, array $binds = []): ?array
    {
        $result = $db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('Simulator test database query failed; diagnostics suppressed.');
        }
        return $result->getRowArray() ?: null;
    }

    private static function rows(BaseConnection $db, string $sql, array $binds = []): array
    {
        $result = $db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('Simulator test database query failed; diagnostics suppressed.');
        }
        return $result->getResultArray();
    }
}

final class MutableSimulatorClock implements Clock
{
    public function __construct(private DateTimeImmutable $instant)
    {
    }

    public function set(DateTimeImmutable $instant): void
    {
        $this->instant = $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->instant = $this->instant->add(new DateInterval('PT' . $seconds . 'S'));
    }

    public function utcNow(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function monotonic(): float
    {
        return (float) $this->instant->format('U.u');
    }
}

final class CaptureLogger implements EventLogger
{
    /** @var list<array{event:string,context:array<string,mixed>}> */
    public array $events = [];

    public function write(string $event, array $context = []): bool
    {
        $this->events[] = ['event' => $event, 'context' => $context];
        return true;
    }
}
