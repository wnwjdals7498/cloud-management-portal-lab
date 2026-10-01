<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Application;

use App\Modules\Identity\Domain\ActorContext;
use App\Modules\Integrations\GatewayDisposition;
use App\Modules\Integrations\GatewayResult;
use App\Modules\Integrations\VmCommandGateway;
use App\Modules\Integrations\VmStatusReader;
use App\Modules\Jobs\Domain\CommandContext;
use App\Modules\Jobs\Domain\CommandValidator;
use App\Modules\Jobs\Domain\CompletionContext;
use App\Modules\Jobs\Domain\CompletionMapping;
use App\Modules\Jobs\Domain\CompletionMappingValidator;
use App\Modules\Jobs\Domain\CompletionMessage;
use App\Modules\Jobs\Domain\CompletionValidator;
use App\Modules\Jobs\Domain\EventIdentity;
use App\Modules\Jobs\Domain\EventIdentityValidator;
use App\Modules\Jobs\Domain\ExpectedStatus;
use App\Modules\Jobs\Domain\VmCommand;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Portal\Shared\AuditSink;
use Portal\Shared\CanonicalJson;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\Identifiers;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class WorkerService
{
    private const TERMINAL_STATES = ['succeeded', 'failed'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditSink $audit,
        private readonly EventLogger $logger,
        private readonly Clock $clock,
        private readonly VmCommandGateway $gateway,
        private readonly VmStatusReader $reader,
    ) {
    }

    /** @return array{claimed:int,sent:int,unknown:int,rejected:int} */
    public function tick(string $workerId): array
    {
        $this->assertWorkerId($workerId);
        $this->reconcileExpiredJobs();
        $this->processInbox();
        $claim = $this->claimOne($workerId);
        if ($claim === null) {
            return ['claimed' => 0, 'sent' => 0, 'unknown' => 0, 'rejected' => 0];
        }

        if (($claim['invalid'] ?? false) === true) {
            return ['claimed' => 1, 'sent' => 0, 'unknown' => 1, 'rejected' => 0];
        }

        $started = $this->clock->monotonic();
        try {
            // The claim transaction committed before any network call.
            $result = $this->gateway->submit($claim['command']);
        } catch (Throwable) {
            $result = GatewayResult::unknown('GATEWAY_EXCEPTION');
        }

        try {
            $outcome = $this->storeGatewayResult($claim, $result);
        } catch (Throwable) {
            $this->logger->write('outbox_result_store_failed', [
                'request_id' => $claim['request_id'],
                'job_id' => $claim['job_id'],
                'attempt' => $claim['attempt'],
                'mode' => $claim['mode'],
                'operation' => 'vmm_dispatch',
                'error_code' => 'RESULT_STORE_FAILED',
                'outcome' => 'failed',
            ]);
            $outcome = 'unknown';
        }

        $duration = max(0.0, $this->clock->monotonic() - $started);
        $logContext = [
            'request_id' => $claim['request_id'],
            'job_id' => $claim['job_id'],
            'attempt' => $claim['attempt'],
            'mode' => $claim['mode'],
            'duration_ms' => $duration * 1000,
            'operation' => 'vmm_dispatch',
            'outcome' => $outcome,
            'worker_id' => $workerId,
        ];
        if ($result->errorCode !== null) {
            $logContext['error_code'] = $result->errorCode;
        }
        $this->logger->write('outbox_dispatch_' . $outcome, $logContext);

        if ($outcome === 'sent') {
            $this->processInbox();
        }

        return [
            'claimed' => 1,
            'sent' => $outcome === 'sent' ? 1 : 0,
            'unknown' => in_array($outcome, ['unknown', 'stale'], true) ? 1 : 0,
            'rejected' => $outcome === 'rejected' ? 1 : 0,
        ];
    }

    /**
     * Validates and durably stores a completion event. A successful return is a receipt ACK,
     * not a statement that the VM/job transition has already been applied.
     *
     * @param array<string, mixed> $payload
     * @return array{disposition:'accepted'|'duplicate'|'content_conflict',event_id:string}
     */
    public function receiveCompletion(array $payload, string $requestId): array
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}\z/', $requestId) !== 1) {
            throw new PortalException('invalid_request_context', 'The request context is invalid.', 400);
        }

        $message = (new CompletionValidator())->validate($payload, 'simulated');
        $canonical = $message->canonicalPayload();
        $fingerprint = CanonicalJson::hash($canonical);
        $receivedAt = $this->timestamp();
        $transactionOpen = false;

        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Completion receipt transaction could not begin.');
            }
            $transactionOpen = true;

            $inserted = $this->db->query(
                'INSERT IGNORE INTO `callback_inbox` (`event_id`,`payload_fingerprint`,`canonical_payload`,`job_id`,`attempt_no`,`external_job_id`,`external_vm_id`,`status`,`observed_at`,`error_code`,`mode`,`state`,`received_at`,`processed_at`,`quarantine_reason`) VALUES (?,?,?,NULL,NULL,?,?,?,?,?,?,?, ?,NULL,NULL)',
                [
                    $message->eventId,
                    $fingerprint,
                    CanonicalJson::encode($canonical),
                    $message->externalJobId,
                    $message->externalVmId,
                    $message->status,
                    $message->observedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                    $message->errorCode,
                    $message->mode,
                    'deferred',
                    $receivedAt,
                ],
            );
            if ($inserted === false) {
                throw new \RuntimeException('Completion receipt could not be stored.');
            }

            if ($this->db->affectedRows() === 1) {
                $this->commitOrFail('completion_receipt_commit_failed');
                $transactionOpen = false;
                $this->logger->write('completion_received', [
                    'request_id' => $requestId,
                    'event_id' => $message->eventId,
                    'job_id' => $message->jobId,
                    'attempt' => $message->attempt,
                    'mode' => $message->mode,
                    'outcome' => 'accepted',
                ]);
                return ['disposition' => 'accepted', 'event_id' => $message->eventId];
            }

            $existing = $this->db->table('callback_inbox')
                ->select(['event_id', 'payload_fingerprint'])
                ->where('event_id', $message->eventId)
                ->get()
                ->getRowArray();
            if (! is_array($existing)) {
                throw new \RuntimeException('Completion identity could not be resolved.');
            }

            if (hash_equals((string) $existing['payload_fingerprint'], $fingerprint)) {
                $this->commitOrFail('completion_duplicate_commit_failed');
                $transactionOpen = false;
                $this->logger->write('completion_duplicate', [
                    'request_id' => $requestId,
                    'event_id' => $message->eventId,
                    'job_id' => $message->jobId,
                    'attempt' => $message->attempt,
                    'mode' => $message->mode,
                    'outcome' => 'duplicate',
                ]);
                return ['disposition' => 'duplicate', 'event_id' => $message->eventId];
            }

            $this->db->table('callback_conflicts')->insert([
                'event_id' => $message->eventId,
                'payload_fingerprint' => $fingerprint,
                'request_id' => $requestId,
                'reason' => 'event_content_conflict',
                'received_at' => $receivedAt,
            ]);
            $this->audit->append([
                'actor_id' => null,
                'actor_kind' => 'vmm',
                'target_type' => 'callback_event',
                'target_id' => $message->eventId,
                'action' => 'vm.callback.content_conflict',
                'decision' => 'denied',
                'reason' => 'event_content_conflict',
                'request_id' => $requestId,
                'job_id' => $message->jobId,
                'attempt' => $message->attempt,
                'mode' => $message->mode,
                'before' => null,
                'after' => ['result' => 'quarantined', 'state' => 'content_conflict'],
            ]);
            $this->commitOrFail('completion_conflict_commit_failed');
            $transactionOpen = false;
            $this->logger->write('completion_content_conflict', [
                'request_id' => $requestId,
                'event_id' => $message->eventId,
                'job_id' => $message->jobId,
                'attempt' => $message->attempt,
                'mode' => $message->mode,
                'error_code' => 'EVENT_CONTENT_CONFLICT',
                'outcome' => 'conflict',
            ]);
            return ['disposition' => 'content_conflict', 'event_id' => $message->eventId];
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            if ($exception instanceof PortalException) {
                throw $exception;
            }
            $this->logger->write('completion_receipt_failed', [
                'request_id' => $requestId,
                'event_id' => $message->eventId,
                'job_id' => $message->jobId,
                'attempt' => $message->attempt,
                'mode' => $message->mode,
                'error_code' => 'COMPLETION_RECEIPT_FAILED',
                'outcome' => 'failed',
            ]);
            throw new PortalException('completion_receipt_unavailable', 'The completion event could not be stored.', 503);
        }
    }

    /** @return array{processed:int,deferred:int,duplicates:int,quarantined:int} */
    public function processInbox(): array
    {
        $config = Runtime::config();
        $limit = $config['simulation']['maximum_pending'] ?? null;
        if (! is_int($limit) || $limit < 1) {
            throw new PortalException('simulation_profile_unavailable', 'The inbox processing limit is unavailable.', 503);
        }

        $eventIds = $this->db->table('callback_inbox')
            ->select('event_id')
            ->whereIn('state', ['queued', 'deferred'])
            ->orderBy('received_at', 'ASC')
            ->orderBy('event_id', 'ASC')
            ->get($limit)
            ->getResultArray();
        $result = ['processed' => 0, 'deferred' => 0, 'duplicates' => 0, 'quarantined' => 0];

        foreach ($eventIds as $candidate) {
            $disposition = $this->processInboxEvent((string) $candidate['event_id']);
            if (array_key_exists($disposition, $result)) {
                $result[$disposition]++;
            }
        }

        return $result;
    }

    /** @return array{observation:?array<string,mixed>,freshness:string,discrepancy:?string,error_code:?string} */
    public function observeJob(ActorContext $actor, string $jobId): array
    {
        $jobs = new JobsService($this->db, $this->audit, $this->logger, $this->clock);
        $jobView = $jobs->getJob($actor, $jobId); // Performs the authoritative member/admin scope check.
        $job = $this->db->table('jobs')
            ->select(['id', 'vm_id', 'state', 'mode', 'current_attempt_no'])
            ->where('id', $jobId)
            ->get()
            ->getRowArray();
        if (! is_array($job)) {
            throw new PortalException('resource_not_found', 'The requested job was not found.', 404);
        }

        $attempt = $this->db->table('job_attempts')
            ->select(['external_job_id', 'external_vm_id'])
            ->where('job_id', $jobId)
            ->where('attempt_no', (int) $job['current_attempt_no'])
            ->get()
            ->getRowArray();
        if (! is_array($attempt) || ! is_string($attempt['external_job_id']) || ! is_string($attempt['external_vm_id'])) {
            return ['observation' => null, 'freshness' => 'unavailable', 'discrepancy' => null, 'error_code' => null];
        }

        try {
            $observed = $this->reader->readJob(new ExpectedStatus(
                (string) $attempt['external_job_id'],
                (string) $attempt['external_vm_id'],
                (string) $job['mode'],
            ));
        } catch (PortalException $exception) {
            $this->logger->write('job_status_read_failed', [
                'request_id' => $actor->requestId,
                'job_id' => $jobId,
                'attempt' => (int) $job['current_attempt_no'],
                'mode' => (string) $job['mode'],
                'error_code' => $exception->errorCode,
                'operation' => 'status_read',
                'outcome' => 'unavailable',
            ]);
            return ['observation' => null, 'freshness' => 'unavailable', 'discrepancy' => null, 'error_code' => $exception->errorCode];
        }

        $observedAt = $observed->observedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        if ($observed->powerState !== null) {
            $this->db->table('vms')
                ->where('id', (string) $job['vm_id'])
                ->groupStart()
                    ->where('observed_at', null)
                    ->orWhere('observed_at <=', $observedAt)
                ->groupEnd()
                ->update(['observed_power_state' => $observed->powerState, 'observed_at' => $observedAt]);
        }

        $discrepancy = null;
        if (in_array($observed->status, self::TERMINAL_STATES, true) && ! in_array($job['state'], self::TERMINAL_STATES, true)) {
            $discrepancy = 'completion_unconfirmed';
        } elseif (in_array($job['state'], self::TERMINAL_STATES, true) && $job['state'] !== $observed->status) {
            $discrepancy = 'confirmed_state_mismatch';
        }

        $this->logger->write('job_status_observed', [
            'request_id' => $actor->requestId,
            'job_id' => $jobId,
            'attempt' => (int) $job['current_attempt_no'],
            'mode' => (string) $job['mode'],
            'operation' => 'status_read',
            'outcome' => 'observed',
            'reason' => $discrepancy ?? 'no_discrepancy',
        ]);

        return [
            'observation' => [
                'status' => $observed->status,
                'power_state' => $observed->powerState,
                'guest_readiness' => $observed->guestReadiness,
                'observed_at' => $observedAt,
                'mode' => $observed->mode,
                'error_code' => $observed->errorCode,
            ],
            'freshness' => 'fresh',
            'discrepancy' => $discrepancy,
            'error_code' => null,
        ];
    }

    private function claimOne(string $workerId): ?array
    {
        $transactionOpen = false;
        $now = $this->timestamp();
        $leaseSeconds = $this->jobTimeoutSeconds() * 2;
        $expiresAt = $this->clock->utcNow()->modify('+' . $leaseSeconds . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Outbox claim transaction could not begin.');
            }
            $transactionOpen = true;
            $candidate = $this->db->query(
                "SELECT o.`id` AS outbox_id,o.`job_id`,o.`attempt_id`,o.`command_version`,o.`command_payload`,o.`state` AS outbox_state,o.`lease_generation`,o.`delivery_count`,j.`vm_id`,j.`actor_id`,j.`state` AS job_state,j.`request_id`,j.`mode`,j.`current_attempt_no`,a.`attempt_no`,a.`external_key_ref`,a.`started_at`,v.`profile_id`,v.`profile_version`,v.`profile_snapshot` FROM `outbox` o JOIN `jobs` j ON j.`id`=o.`job_id` JOIN `job_attempts` a ON a.`id`=o.`attempt_id` JOIN `vms` v ON v.`id`=j.`vm_id` WHERE (((o.`state` IN ('queued','unknown')) AND o.`available_at`<=?) OR (o.`state`='leased' AND o.`lease_expires_at`<=?)) ORDER BY o.`available_at` ASC,o.`id` ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
                [$now, $now],
            )->getRowArray();

            if (! is_array($candidate)) {
                $this->commitOrFail('outbox_claim_commit_failed');
                $transactionOpen = false;
                return null;
            }

            if (in_array($candidate['job_state'], self::TERMINAL_STATES, true)) {
                $this->db->table('outbox')->where('id', $candidate['outbox_id'])->update([
                    'state' => 'sent', 'lease_owner' => null, 'lease_token' => null, 'lease_expires_at' => null,
                ]);
                $this->commitOrFail('outbox_terminal_cleanup_failed');
                $transactionOpen = false;
                return null;
            }

            try {
                $decoded = json_decode((string) $candidate['command_payload'], true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $this->quarantineClaim($candidate, 'PERSISTED_COMMAND_INVALID', $now, $transactionOpen);
            }
            if (! is_array($decoded) || array_is_list($decoded)) {
                return $this->quarantineClaim($candidate, 'PERSISTED_COMMAND_INVALID', $now, $transactionOpen);
            }

            try {
                $profileSnapshot = json_decode((string) $candidate['profile_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $this->quarantineClaim($candidate, 'PROFILE_SNAPSHOT_INVALID', $now, $transactionOpen);
            }
            if (! is_array($profileSnapshot) || array_is_list($profileSnapshot)) {
                return $this->quarantineClaim($candidate, 'PROFILE_SNAPSHOT_INVALID', $now, $transactionOpen);
            }

            $attemptNo = (int) $candidate['attempt_no'];
            $profile = [
                'profile_id' => (string) $candidate['profile_id'],
                'profile_version' => (string) $candidate['profile_version'],
                'snapshot' => $profileSnapshot,
            ];
            $externalKey = $decoded['external_idempotency_key'] ?? null;
            if (! is_string($externalKey) || ! hash_equals((string) $candidate['external_key_ref'], hash('sha256', $externalKey))) {
                return $this->quarantineClaim($candidate, 'EXTERNAL_KEY_REF_MISMATCH', $now, $transactionOpen);
            }

            try {
                $command = (new CommandValidator())->validate($decoded, new CommandContext(
                    (string) $candidate['request_id'],
                    (string) $candidate['job_id'],
                    (string) $candidate['vm_id'],
                    $attemptNo,
                    $externalKey,
                    $profile,
                    (string) $candidate['mode'],
                ));
            } catch (Throwable) {
                return $this->quarantineClaim($candidate, 'PERSISTED_COMMAND_INVALID', $now, $transactionOpen);
            }

            $token = bin2hex(random_bytes(32));
            $generation = (int) $candidate['lease_generation'] + 1;
            $updated = $this->db->table('outbox')->where('id', $candidate['outbox_id'])->update([
                'state' => 'leased',
                'lease_owner' => $workerId,
                'lease_token' => $token,
                'lease_generation' => $generation,
                'lease_expires_at' => $expiresAt,
                'delivery_count' => (int) $candidate['delivery_count'] + 1,
            ]);
            if ($updated === false || $this->db->affectedRows() !== 1) {
                throw new \RuntimeException('Outbox lease could not be acquired.');
            }

            $this->db->table('jobs')->where('id', $candidate['job_id'])->where('current_attempt_no', $attemptNo)->update([
                'state' => 'dispatching',
                'updated_at' => $now,
            ]);
            $this->db->table('job_attempts')->where('id', $candidate['attempt_id'])->where('attempt_no', $attemptNo)->update([
                'state' => 'dispatching',
                'started_at' => $candidate['started_at'] ?? $now,
            ]);

            $this->commitOrFail('outbox_claim_commit_failed');
            $transactionOpen = false;

            return [
                'outbox_id' => (string) $candidate['outbox_id'],
                'attempt_id' => (string) $candidate['attempt_id'],
                'job_id' => (string) $candidate['job_id'],
                'vm_id' => (string) $candidate['vm_id'],
                'actor_id' => (int) $candidate['actor_id'],
                'request_id' => (string) $candidate['request_id'],
                'worker_id' => $workerId,
                'mode' => (string) $candidate['mode'],
                'attempt' => $attemptNo,
                'lease_token' => $token,
                'lease_generation' => $generation,
                'command' => $command,
            ];
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            if ($exception instanceof PortalException) {
                throw $exception;
            }
            $this->logger->write('outbox_claim_failed', [
                'mode' => 'simulated', 'operation' => 'outbox_claim',
                'error_code' => 'OUTBOX_CLAIM_FAILED', 'outcome' => 'failed',
            ]);
            throw new PortalException('outbox_unavailable', 'The work queue is unavailable.', 503);
        }
    }

    private function quarantineClaim(array $candidate, string $reason, string $now, bool &$transactionOpen): ?array
    {
        $this->db->table('outbox')->where('id', $candidate['outbox_id'])->update([
            'state' => 'unknown', 'lease_owner' => null, 'lease_token' => null,
            'lease_generation' => ((int) $candidate['lease_generation']) + 1,
            'lease_expires_at' => null, 'last_error_code' => $reason,
            'available_at' => $this->clock->utcNow()->modify('+' . $this->jobTimeoutSeconds() . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        $this->db->table('jobs')->where('id', $candidate['job_id'])->update([
            'state' => 'reconciliation_required', 'updated_at' => $now,
        ]);
        $this->db->table('job_attempts')->where('id', $candidate['attempt_id'])->update([
            'state' => 'unknown', 'error_code' => $reason,
        ]);
        $this->audit->append([
            'actor_id' => (int) $candidate['actor_id'], 'actor_kind' => 'user',
            'target_type' => 'job', 'target_id' => (string) $candidate['job_id'],
            'action' => 'vm.create.command_invalid', 'decision' => 'denied', 'reason' => strtolower($reason),
            'request_id' => (string) $candidate['request_id'], 'job_id' => (string) $candidate['job_id'],
            'attempt' => (int) $candidate['attempt_no'], 'mode' => (string) $candidate['mode'],
            'before' => ['state' => (string) $candidate['job_state']],
            'after' => ['state' => 'reconciliation_required', 'result' => 'unknown'],
        ]);
        $this->commitOrFail('outbox_invalid_command_commit_failed');
        $transactionOpen = false;
        $this->logger->write('outbox_command_quarantined', [
            'request_id' => (string) $candidate['request_id'], 'job_id' => (string) $candidate['job_id'],
            'attempt' => (int) $candidate['attempt_no'], 'mode' => (string) $candidate['mode'],
            'error_code' => $reason, 'operation' => 'vmm_dispatch', 'outcome' => 'unknown',
        ]);
        return ['invalid' => true];
    }

    private function storeGatewayResult(array $claim, GatewayResult $result): string
    {
        $transactionOpen = false;
        $now = $this->timestamp();
        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Gateway result transaction could not begin.');
            }
            $transactionOpen = true;

            $outbox = $this->db->query(
                "SELECT `id`,`job_id`,`attempt_id`,`lease_owner`,`lease_token`,`lease_generation`,`state` FROM `outbox` WHERE `id`=? FOR UPDATE",
                [$claim['outbox_id']],
            )->getRowArray();
            if (! is_array($outbox)
                || $outbox['state'] !== 'leased'
                || $outbox['lease_owner'] !== $claim['worker_id']
                || ! is_string($outbox['lease_token'])
                || ! hash_equals((string) $claim['lease_token'], (string) $outbox['lease_token'])
                || (int) $outbox['lease_generation'] !== (int) $claim['lease_generation']) {
                $this->commitOrFail('stale_worker_result_commit_failed');
                $transactionOpen = false;
                return 'stale';
            }

            $job = $this->db->query('SELECT `id`,`vm_id`,`actor_id`,`operation`,`state`,`mode`,`request_id`,`current_attempt_no` FROM `jobs` WHERE `id`=? FOR UPDATE', [$claim['job_id']])->getRowArray();
            $attempt = $this->db->query('SELECT `id`,`job_id`,`attempt_no`,`external_job_id`,`external_vm_id`,`state` FROM `job_attempts` WHERE `id`=? FOR UPDATE', [$claim['attempt_id']])->getRowArray();
            if (! is_array($job) || ! is_array($attempt)) {
                throw new \RuntimeException('Gateway result mapping is unavailable.');
            }

            if (in_array($job['state'], self::TERMINAL_STATES, true)) {
                $this->closeOutboxLease((string) $claim['outbox_id'], 'sent', null);
                $this->commitOrFail('terminal_dispatch_cleanup_failed');
                $transactionOpen = false;
                return 'sent';
            }

            if ($result->disposition === GatewayDisposition::ACCEPTED && $result->receipt !== null) {
                $receipt = $result->receipt;
                $mappingConflict = ($attempt['external_job_id'] !== null && $attempt['external_job_id'] !== $receipt->externalJobId)
                    || ($attempt['external_vm_id'] !== null && $attempt['external_vm_id'] !== $receipt->externalVmId);

                if ($mappingConflict || $receipt->mode !== $job['mode']) {
                    $this->markUnknown($job, $attempt, $claim, 'EXTERNAL_MAPPING_CONFLICT', $now);
                    $this->commitOrFail('unknown_dispatch_commit_failed');
                    $transactionOpen = false;
                    return 'unknown';
                }

                $this->db->table('job_attempts')->where('id', $claim['attempt_id'])->where('attempt_no', $claim['attempt'])->update([
                    'external_job_id' => $receipt->externalJobId,
                    'external_vm_id' => $receipt->externalVmId,
                    'state' => 'running',
                    'observed_at' => $receipt->observedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                    'error_code' => null,
                ]);
                $this->db->table('jobs')->where('id', $claim['job_id'])->where('current_attempt_no', $claim['attempt'])->whereNotIn('state', self::TERMINAL_STATES)->update([
                    'state' => 'running', 'updated_at' => $now,
                ]);
                $this->closeOutboxLease((string) $claim['outbox_id'], 'sent', null);
                $this->appendJobAudit($job, 'vm.create.dispatched', 'allowed', 'external_accepted', (int) $claim['attempt'], (string) $job['state'], 'running');
                $this->commitOrFail('accepted_dispatch_commit_failed');
                $transactionOpen = false;
                return 'sent';
            }

            if ($result->disposition === GatewayDisposition::REJECTED) {
                $errorCode = $this->safeErrorCode($result->errorCode, 'VMM_REQUEST_REJECTED');
                if (! $this->settleQuota((string) $job['id'], (int) $job['actor_id'], 'released', $now)) {
                    $this->markUnknown($job, $attempt, $claim, 'QUOTA_RESERVATION_UNAVAILABLE', $now);
                    $this->commitOrFail('quota_invariant_dispatch_commit_failed');
                    $transactionOpen = false;
                    return 'unknown';
                }

                $this->db->table('job_attempts')->where('id', $claim['attempt_id'])->update([
                    'state' => 'failed', 'finished_at' => $now, 'error_code' => $errorCode,
                ]);
                $this->db->table('jobs')->where('id', $claim['job_id'])->where('current_attempt_no', $claim['attempt'])->whereNotIn('state', self::TERMINAL_STATES)->update([
                    'state' => 'failed', 'updated_at' => $now, 'finished_at' => $now,
                ]);
                $this->db->table('vms')->where('id', $claim['vm_id'])->update(['lifecycle_state' => 'failed', 'updated_at' => $now]);
                $this->closeOutboxLease((string) $claim['outbox_id'], 'sent', $errorCode);
                $this->appendJobAudit($job, 'vm.create.failed', 'allowed', 'external_rejected', (int) $claim['attempt'], (string) $job['state'], 'failed', $errorCode);
                $this->commitOrFail('rejected_dispatch_commit_failed');
                $transactionOpen = false;
                return 'rejected';
            }

            $errorCode = $this->safeErrorCode($result->errorCode, 'VMM_RECEIPT_UNKNOWN');
            $this->markUnknown($job, $attempt, $claim, $errorCode, $now);
            $this->commitOrFail('unknown_dispatch_commit_failed');
            $transactionOpen = false;
            return 'unknown';
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            throw $exception;
        }
    }

    private function markUnknown(array $job, array $attempt, array $claim, string $errorCode, string $now): void
    {
        $availableAt = $this->clock->utcNow()->modify('+' . $this->jobTimeoutSeconds() . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->db->table('job_attempts')->where('id', $claim['attempt_id'])->update(['state' => 'unknown', 'error_code' => $errorCode]);
        $this->db->table('jobs')->where('id', $claim['job_id'])->whereNotIn('state', self::TERMINAL_STATES)->update([
            'state' => 'reconciliation_required', 'updated_at' => $now,
        ]);
        $this->db->table('outbox')->where('id', $claim['outbox_id'])->where('lease_token', $claim['lease_token'])->where('lease_generation', $claim['lease_generation'])->update([
            'state' => 'unknown', 'available_at' => $availableAt, 'lease_owner' => null,
            'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => $errorCode,
        ]);
        $this->appendJobAudit($job, 'vm.create.unknown', 'allowed', 'receipt_uncertain', (int) $claim['attempt'], (string) $job['state'], 'reconciliation_required', $errorCode);
    }

    private function closeOutboxLease(string $outboxId, string $state, ?string $errorCode): void
    {
        $this->db->table('outbox')->where('id', $outboxId)->update([
            'state' => $state,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => $errorCode,
        ]);
    }

    /** @return array{processed:int,deferred:int,duplicates:int,quarantined:int}|array{disposition:string} */
    private function processInboxEvent(string $eventId): array|string
    {
        $transactionOpen = false;
        $now = $this->timestamp();
        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Inbox transaction could not begin.');
            }
            $transactionOpen = true;
            $inbox = $this->db->query("SELECT * FROM `callback_inbox` WHERE `event_id`=? AND `state` IN ('queued','deferred') FOR UPDATE SKIP LOCKED", [$eventId])->getRowArray();
            if (! is_array($inbox)) {
                $this->commitOrFail('inbox_skip_commit_failed');
                $transactionOpen = false;
                return 'deferred';
            }

            $canonical = json_decode((string) $inbox['canonical_payload'], true, 64, JSON_THROW_ON_ERROR);
            $message = (new CompletionValidator())->validate($canonical, 'simulated');
            $job = $this->db->query('SELECT `id`,`vm_id`,`actor_id`,`operation`,`state`,`request_id`,`mode`,`current_attempt_no` FROM `jobs` WHERE `id`=? FOR UPDATE', [$message->jobId])->getRowArray();
            if (! is_array($job)) {
                $this->setInboxDisposition($eventId, null, null, 'deferred', null, null);
                $this->commitOrFail('inbox_defer_commit_failed');
                $transactionOpen = false;
                return 'deferred';
            }

            $attempt = $this->db->query('SELECT `id`,`attempt_no`,`external_job_id`,`external_vm_id`,`state` FROM `job_attempts` WHERE `job_id`=? AND `attempt_no`=? FOR UPDATE', [$message->jobId, $message->attempt])->getRowArray();
            if (! is_array($attempt)) {
                $disposition = (int) $job['current_attempt_no'] > $message->attempt ? 'stale_attempt' : null;
                if ($disposition === null) {
                    $this->setInboxDisposition($eventId, null, null, 'deferred', null, null);
                    $this->commitOrFail('inbox_defer_commit_failed');
                    $transactionOpen = false;
                    return 'deferred';
                }
                $this->setInboxDisposition($eventId, null, null, 'quarantined', $now, $disposition);
                $this->appendCallbackAudit($job, $message, 'vm.callback.stale_attempt', $disposition, 'denied');
                $this->commitOrFail('inbox_stale_commit_failed');
                $transactionOpen = false;
                return 'quarantined';
            }

            $this->setInboxMapping($eventId, $message->jobId, $message->attempt);
            if ((string) $job['mode'] !== $message->mode || (string) $job['operation'] !== 'create') {
                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'quarantined', $now, 'job_context_mismatch');
                $this->appendCallbackAudit($job, $message, 'vm.callback.quarantined', 'job_context_mismatch', 'denied');
                $this->commitOrFail('inbox_context_conflict_commit_failed');
                $transactionOpen = false;
                return 'quarantined';
            }

            if ($message->attempt !== (int) $job['current_attempt_no']) {
                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'quarantined', $now, 'stale_attempt');
                $this->appendCallbackAudit($job, $message, 'vm.callback.stale_attempt', 'stale_attempt', 'denied');
                $this->commitOrFail('inbox_stale_commit_failed');
                $transactionOpen = false;
                return 'quarantined';
            }

            if (! is_string($attempt['external_job_id']) || ! is_string($attempt['external_vm_id'])) {
                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'deferred', null, null);
                $this->commitOrFail('inbox_mapping_defer_commit_failed');
                $transactionOpen = false;
                return 'deferred';
            }

            if ($attempt['external_job_id'] !== $message->externalJobId || $attempt['external_vm_id'] !== $message->externalVmId) {
                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'quarantined', $now, 'external_mapping_mismatch');
                $this->appendCallbackAudit($job, $message, 'vm.callback.quarantined', 'external_mapping_mismatch', 'denied');
                $this->commitOrFail('inbox_external_conflict_commit_failed');
                $transactionOpen = false;
                return 'quarantined';
            }

            if (in_array($job['state'], self::TERMINAL_STATES, true)) {
                if ($job['state'] === $message->status) {
                    $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'processed', $now, null);
                    $this->commitOrFail('inbox_terminal_duplicate_commit_failed');
                    $transactionOpen = false;
                    return 'duplicates';
                }

                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'quarantined', $now, 'terminal_conflict');
                $this->appendCallbackAudit($job, $message, 'vm.callback.terminal_conflict', 'terminal_conflict', 'denied');
                $this->commitOrFail('inbox_terminal_conflict_commit_failed');
                $transactionOpen = false;
                return 'quarantined';
            }

            if (! in_array($job['state'], ['dispatching', 'running', 'reconciliation_required'], true)) {
                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'deferred', null, null);
                $this->commitOrFail('inbox_state_defer_commit_failed');
                $transactionOpen = false;
                return 'deferred';
            }

            if (! $this->settleQuota((string) $job['id'], (int) $job['actor_id'], $message->status === 'succeeded' ? 'consumed' : 'released', $now)) {
                $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'quarantined', $now, 'quota_reservation_unavailable');
                $this->appendCallbackAudit($job, $message, 'vm.callback.quarantined', 'quota_reservation_unavailable', 'denied');
                $this->commitOrFail('inbox_quota_conflict_commit_failed');
                $transactionOpen = false;
                return 'quarantined';
            }

            $this->db->table('job_attempts')->where('id', $attempt['id'])->update([
                'state' => $message->status,
                'external_job_id' => $message->externalJobId,
                'external_vm_id' => $message->externalVmId,
                'finished_at' => $now,
                'observed_at' => $message->observedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'error_code' => $message->errorCode,
            ]);
            $this->db->table('jobs')->where('id', $job['id'])->where('current_attempt_no', $message->attempt)->whereNotIn('state', self::TERMINAL_STATES)->update([
                'state' => $message->status, 'updated_at' => $now, 'finished_at' => $now,
            ]);
            $this->db->table('vms')->where('id', $job['vm_id'])->update([
                'lifecycle_state' => $message->status === 'succeeded' ? 'active' : 'failed',
                'updated_at' => $now,
            ]);
            $this->setInboxDisposition($eventId, $message->jobId, $message->attempt, 'processed', $now, null);
            $this->appendCallbackAudit(
                $job,
                $message,
                $message->status === 'succeeded' ? 'vm.create.completed' : 'vm.create.failed',
                $message->status === 'succeeded' ? 'completion_succeeded' : 'completion_failed',
                'allowed',
            );
            $this->commitOrFail('inbox_apply_commit_failed');
            $transactionOpen = false;

            // Fence any in-flight HTTP response after durable completion has won the race.
            $this->closeOutboxForAttempt($message->jobId, $message->attempt);
            $this->logger->write('completion_applied', [
                'request_id' => (string) $job['request_id'], 'job_id' => $message->jobId,
                'event_id' => $message->eventId, 'attempt' => $message->attempt,
                'mode' => $message->mode, 'operation' => 'completion_apply',
                'outcome' => $message->status,
            ]);
            return 'processed';
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            $this->logger->write('inbox_process_failed', [
                'event_id' => $eventId, 'mode' => 'simulated',
                'operation' => 'completion_apply', 'error_code' => 'INBOX_PROCESS_FAILED', 'outcome' => 'failed',
            ]);
            if ($exception instanceof PortalException) {
                throw $exception;
            }
            throw new PortalException('inbox_unavailable', 'The completion event could not be processed.', 503);
        }
    }

    private function settleQuota(string $jobId, int $actorId, string $state, string $now): bool
    {
        $reservation = $this->db->query('SELECT `id`,`state` FROM `quota_reservations` WHERE `job_id`=? AND `actor_id`=? FOR UPDATE', [$jobId, $actorId])->getRowArray();
        if (! is_array($reservation) || $reservation['state'] !== 'reserved' || ! in_array($state, ['consumed', 'released'], true)) {
            return false;
        }

        $quota = $this->db->query('SELECT `reserved_count` FROM `quota_accounts` WHERE `actor_id`=? FOR UPDATE', [$actorId])->getRowArray();
        if (! is_array($quota) || (int) $quota['reserved_count'] < 1) {
            return false;
        }

        $this->db->table('quota_reservations')->where('id', $reservation['id'])->where('state', 'reserved')->update([
            'state' => $state, 'updated_at' => $now,
        ]);
        $sql = $state === 'consumed'
            ? 'UPDATE `quota_accounts` SET `reserved_count`=`reserved_count`-1,`consumed_count`=`consumed_count`+1,`updated_at`=? WHERE `actor_id`=? AND `reserved_count`>0'
            : 'UPDATE `quota_accounts` SET `reserved_count`=`reserved_count`-1,`updated_at`=? WHERE `actor_id`=? AND `reserved_count`>0';
        $updated = $this->db->query($sql, [$now, $actorId]);
        return $updated !== false && $this->db->affectedRows() === 1;
    }

    private function setInboxMapping(string $eventId, string $jobId, int $attempt): void
    {
        $this->db->table('callback_inbox')->where('event_id', $eventId)->update(['job_id' => $jobId, 'attempt_no' => $attempt]);
    }

    private function setInboxDisposition(string $eventId, ?string $jobId, ?int $attempt, string $state, ?string $processedAt, ?string $reason): void
    {
        $this->db->table('callback_inbox')->where('event_id', $eventId)->update([
            'job_id' => $jobId,
            'attempt_no' => $attempt,
            'state' => $state,
            'processed_at' => $processedAt,
            'quarantine_reason' => $reason,
        ]);
    }

    private function appendCallbackAudit(array $job, CompletionMessage $message, string $action, string $reason, string $decision): void
    {
        $this->audit->append([
            'actor_id' => (int) $job['actor_id'],
            'actor_kind' => 'user',
            'target_type' => 'vm',
            'target_id' => (string) $job['vm_id'],
            'action' => $action,
            'decision' => $decision,
            'reason' => $reason,
            'request_id' => (string) $job['request_id'],
            'job_id' => (string) $job['id'],
            'attempt' => $message->attempt,
            'mode' => $message->mode,
            'before' => ['state' => (string) $job['state']],
            'after' => [
                'state' => $decision === 'allowed' ? $message->status : 'quarantined',
                'result' => $decision === 'allowed' ? $message->status : 'denied',
                'mode' => $message->mode,
            ],
        ]);
    }

    private function appendJobAudit(array $job, string $action, string $decision, string $reason, int $attempt, string $before, string $after, ?string $errorCode = null): void
    {
        $afterSummary = ['state' => $after, 'operation' => (string) $job['operation'], 'mode' => (string) $job['mode']];
        if ($errorCode !== null) {
            $afterSummary['result'] = $errorCode;
        }
        $this->audit->append([
            'actor_id' => (int) $job['actor_id'], 'actor_kind' => 'user',
            'target_type' => 'job', 'target_id' => (string) $job['id'],
            'action' => $action, 'decision' => $decision, 'reason' => $reason,
            'request_id' => (string) $job['request_id'], 'job_id' => (string) $job['id'],
            'attempt' => $attempt, 'mode' => (string) $job['mode'],
            'before' => ['state' => $before], 'after' => $afterSummary,
        ]);
    }

    private function closeOutboxForAttempt(string $jobId, int $attempt): void
    {
        $row = $this->db->table('job_attempts')->select('id')->where('job_id', $jobId)->where('attempt_no', $attempt)->get()->getRowArray();
        if (! is_array($row)) {
            return;
        }
        $this->db->table('outbox')->where('job_id', $jobId)->where('attempt_id', $row['id'])->whereIn('state', ['queued', 'unknown', 'leased'])->update([
            'state' => 'sent', 'lease_owner' => null, 'lease_token' => null,
            'lease_expires_at' => null, 'last_error_code' => null,
        ]);
    }

    private function reconcileExpiredJobs(): void
    {
        $deadline = $this->clock->utcNow()->modify('-' . $this->jobTimeoutSeconds() . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $now = $this->timestamp();
        $transactionOpen = false;
        try {
            if (! $this->db->transBegin()) {
                return;
            }
            $transactionOpen = true;
            $rows = $this->db->query(
                "SELECT j.`id`,j.`vm_id`,j.`actor_id`,j.`operation`,j.`state`,j.`request_id`,j.`mode`,j.`current_attempt_no` FROM `jobs` j WHERE j.`state`='running' AND j.`updated_at`<=? ORDER BY j.`updated_at`,j.`id` LIMIT ? FOR UPDATE SKIP LOCKED",
                [$deadline, $this->inboxBatchLimit()],
            )->getResultArray();

            foreach ($rows as $job) {
                $attempt = (int) $job['current_attempt_no'];
                $this->db->table('jobs')->where('id', $job['id'])->where('state', 'running')->update([
                    'state' => 'reconciliation_required', 'updated_at' => $now,
                ]);
                $this->appendJobAudit($job, 'vm.create.reconcile', 'allowed', 'completion_timeout', $attempt, 'running', 'reconciliation_required');
                $this->logger->write('job_reconciliation_required', [
                    'request_id' => (string) $job['request_id'], 'job_id' => (string) $job['id'],
                    'attempt' => $attempt, 'mode' => (string) $job['mode'],
                    'operation' => 'completion_timeout', 'outcome' => 'reconciliation_required',
                ]);
            }

            $this->commitOrFail('job_reconciliation_commit_failed');
            $transactionOpen = false;
        } catch (Throwable) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            $this->logger->write('job_reconciliation_scan_failed', [
                'mode' => 'simulated', 'operation' => 'completion_timeout',
                'error_code' => 'RECONCILIATION_SCAN_FAILED', 'outcome' => 'failed',
            ]);
        }
    }

    private function inboxBatchLimit(): int
    {
        $config = Runtime::config();
        $limit = $config['simulation']['maximum_pending'] ?? null;
        if (! is_int($limit) || $limit < 1) {
            throw new PortalException('simulation_profile_unavailable', 'The simulation processing limit is unavailable.', 503);
        }
        return $limit;
    }

    private function jobTimeoutSeconds(): int
    {
        $config = Runtime::config();
        $timeout = $config['job_timeout_seconds'] ?? 20;
        if (! is_int($timeout) || $timeout < 1) {
            throw new PortalException('job_timeout_unavailable', 'The job timeout policy is unavailable.', 503);
        }
        return $timeout;
    }

    private function commitOrFail(string $errorCode): void
    {
        if (! $this->db->transStatus() || ! $this->db->transCommit()) {
            throw new PortalException($errorCode, 'The work record could not be committed.', 503);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->utcNow()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertWorkerId(string $workerId): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}\z/', $workerId) !== 1) {
            throw new PortalException('invalid_worker_identity', 'The worker identity is invalid.', 400);
        }
    }

    private function safeErrorCode(?string $value, string $fallback): string
    {
        return is_string($value) && strlen($value) <= 64 && preg_match('/\A[A-Z][A-Z0-9_]*\z/', $value) === 1
            ? $value
            : $fallback;
    }
}
