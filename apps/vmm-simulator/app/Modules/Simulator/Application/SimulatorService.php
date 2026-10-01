<?php
declare(strict_types=1);

namespace App\Modules\Simulator\Application;

use App\Modules\Simulator\Domain\ScenarioSelector;
use App\Modules\Simulator\Domain\SimulationCommandValidator;
use App\Modules\Simulator\Domain\SimulationProfileValidator;
use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Portal\Shared\CanonicalJson;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\Identifiers;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class SimulatorService
{
    private const CAPACITY_LOCK = 'portal_simulator_pending_capacity';
    private const MAX_CALLBACK_ATTEMPTS = 5;
    private const MAX_RETRY_DELAY_SECONDS = 60;

    /** @var null|\Closure(array<string, mixed>):int */
    private readonly ?\Closure $completionSender;

    /** @var array<string, mixed>|null */
    private ?array $runtimeConfig = null;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly EventLogger $logger,
        ?callable $completionSender = null,
    ) {
        $this->completionSender = $completionSender === null ? null : \Closure::fromCallable($completionSender);
    }

    /** @param array<string, mixed> $command
     *  @return array{external_job_id:string,external_vm_id:string,status:string,observed_at:string,mode:string}
     */
    public function submit(array $command, ?string $forcedScenario = null): array
    {
        $validated = (new SimulationCommandValidator())->validate($command);
        $profileConfig = $this->simulationConfig();
        $maximumPending = (int) $profileConfig['maximum_pending'];
        $profile = (new SimulationProfileValidator())->validate($profileConfig, $maximumPending);

        if ($forcedScenario !== null && ! $this->forcedScenarioIsAllowed()) {
            throw new PortalException('FORCED_SCENARIO_FORBIDDEN', 'Forced scenarios are available only in the development CLI.', 403);
        }

        $externalKeyDigest = hash('sha256', $validated['external_idempotency_key']);
        $commandFingerprint = CanonicalJson::hash($validated);
        $capacityLockHeld = false;
        $transactionOpen = false;

        try {
            $capacityLockHeld = $this->acquireCapacityLock();
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Could not begin simulator transaction.');
            }
            $transactionOpen = true;

            $existingKey = $this->row(
                'SELECT * FROM `sim_jobs` WHERE `external_key_digest` = ? FOR UPDATE',
                [$externalKeyDigest],
            );
            if ($existingKey !== null) {
                if (! hash_equals((string) $existingKey['command_fingerprint'], $commandFingerprint)) {
                    $this->rollback();
                    $transactionOpen = false;
                    throw new PortalException('SIMULATOR_IDEMPOTENCY_CONFLICT', 'The external request key was reused with different command content.', 409);
                }
                if (! $this->db->transCommit()) {
                    throw new \RuntimeException('Could not commit simulator replay lookup.');
                }
                $transactionOpen = false;
                $this->log('sim.command.replayed', [
                    'job_id' => (string) $existingKey['api_job_id'],
                    'attempt' => (int) $existingKey['attempt_no'],
                    'outcome' => 'existing',
                ]);
                return $this->receipt($existingKey);
            }

            $existingAttempt = $this->row(
                'SELECT * FROM `sim_jobs` WHERE `api_job_id` = ? AND `attempt_no` = ? FOR UPDATE',
                [$validated['job_id'], $validated['attempt']],
            );
            if ($existingAttempt !== null) {
                $this->rollback();
                $transactionOpen = false;
                throw new PortalException('SIMULATOR_ATTEMPT_CONFLICT', 'The API job attempt already has a different simulator request.', 409);
            }

            $pending = $this->rows(
                "SELECT `id` FROM `sim_jobs` FORCE INDEX (`idx_sim_due`) WHERE `state` IN ('accepted','running') ORDER BY `state`,`due_at`,`id` FOR UPDATE",
            );
            if (count($pending) >= $maximumPending) {
                $this->rollback();
                $transactionOpen = false;
                throw new PortalException('SIMULATOR_CAPACITY', 'The simulator has reached its pending request limit.', 503);
            }

            $now = $this->clock->utcNow()->setTimezone(new DateTimeZone('UTC'));
            $draw = (new ScenarioSelector())->select($validated['job_id'], $profile, $forcedScenario);
            $scenario = $draw->scenario;
            $digest = $draw->digest;
            $values = $profile->values;
            $delay = $this->delayFor($scenario, $digest, $values);
            $dueAt = $now->add(new DateInterval('PT' . $delay . 'S'));
            $simJobId = Identifiers::new('sjb');
            $externalVmId = self::externalVmId($validated['vm_id']);

            $jobRecord = [
                'id' => $simJobId,
                'api_job_id' => $validated['job_id'],
                'attempt_no' => $validated['attempt'],
                'vm_id' => $validated['vm_id'],
                'external_key_digest' => $externalKeyDigest,
                'command_fingerprint' => $commandFingerprint,
                'command_payload' => CanonicalJson::encode($validated),
                'mode' => 'simulated',
                'profile_version' => $values['profile_version'],
                'profile_snapshot' => CanonicalJson::encode($validated['profile']['snapshot']),
                'seed_ref' => substr(hash('sha256', $values['seed']), 0, 48),
                'draw_algorithm' => $draw->algorithm,
                'selected_scenario' => $scenario,
                'state' => 'accepted',
                'due_at' => self::sqlTimestamp($dueAt),
                'created_at' => self::sqlTimestamp($now),
            ];
            $this->insert('sim_jobs', $jobRecord);

            $existingVm = $this->row('SELECT `id` FROM `sim_vms` WHERE `api_vm_id` = ? FOR UPDATE', [$validated['vm_id']]);
            if ($existingVm === null) {
                $this->insert('sim_vms', [
                    'id' => $externalVmId,
                    'sim_job_id' => $simJobId,
                    'api_vm_id' => $validated['vm_id'],
                    'state' => 'pending',
                    'outcome' => $scenario,
                    'observed_at' => null,
                ]);
            } else {
                $this->update('sim_vms', [
                    'sim_job_id' => $simJobId,
                    'state' => 'pending',
                    'outcome' => $scenario,
                    'observed_at' => null,
                ], ['api_vm_id' => $validated['vm_id']]);
            }

            $this->persistDeliveries($simJobId, $validated, $externalVmId, $scenario, $digest, $values, $dueAt);

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new \RuntimeException('Simulator persistence transaction failed.');
            }
            $transactionOpen = false;

            $this->log('sim.command.accepted', [
                'job_id' => $validated['job_id'],
                'attempt' => $validated['attempt'],
                'mode' => 'simulated',
                'outcome' => 'accepted',
            ]);

            return [
                'external_job_id' => $simJobId,
                'external_vm_id' => $externalVmId,
                'status' => 'accepted',
                'observed_at' => self::wireTimestamp($now),
                'mode' => 'simulated',
            ];
        } catch (PortalException $exception) {
            if ($transactionOpen) {
                $this->rollback();
            }
            throw $exception;
        } catch (Throwable) {
            if ($transactionOpen) {
                $this->rollback();
            }
            $this->log('sim.command.failed', [
                'job_id' => $validated['job_id'],
                'attempt' => $validated['attempt'],
                'error_code' => 'SIMULATOR_PERSISTENCE_FAILURE',
            ]);
            throw new PortalException('SIMULATOR_UNAVAILABLE', 'The simulator could not persist the command.', 503);
        } finally {
            if ($capacityLockHeld) {
                $this->releaseCapacityLock();
            }
        }
    }

    /** @return array<string, mixed> */
    public function getJob(string $externalJobId): array
    {
        if (trim($externalJobId) === '' || strlen($externalJobId) > 64 || preg_match('/[\x00-\x20\x7f]/', $externalJobId) === 1) {
            throw new PortalException('INVALID_SIMULATOR_JOB_ID', 'The simulator job reference is invalid.', 400);
        }

        $job = $this->row('SELECT * FROM `sim_jobs` WHERE `id` = ?', [$externalJobId]);
        if ($job === null) {
            throw new PortalException('SIMULATOR_JOB_NOT_FOUND', 'The simulator job was not found.', 404);
        }

        $observedAt = in_array($job['state'], ['succeeded', 'failed'], true)
            ? (string) $job['due_at']
            : (string) $job['created_at'];
        $observation = [
            'external_job_id' => (string) $job['id'],
            'external_vm_id' => self::externalVmId((string) $job['vm_id']),
            'status' => (string) $job['state'],
            'observed_at' => self::wireTimestamp(self::parseSqlTimestamp($observedAt)),
            'mode' => 'simulated',
        ];

        if ($job['state'] === 'failed') {
            $delivery = $this->row(
                "SELECT `payload` FROM `sim_deliveries` WHERE `sim_job_id` = ? AND `delivery_kind` = 'completion' ORDER BY `sequence_no` LIMIT 1",
                [$job['id']],
            );
            if ($delivery !== null) {
                $payload = self::decodeObject((string) $delivery['payload']);
                if (is_string($payload['error_code'] ?? null)) {
                    $observation['error_code'] = $payload['error_code'];
                }
            }
        }

        return $observation;
    }

    /** Reserve the original missing completion payload for a CLI-only reconciliation action. */
    public function scheduleMissingCallbackResend(string $externalJobId): array
    {
        if (trim($externalJobId) === '' || strlen($externalJobId) > 64 || preg_match('/[\x00-\x20\x7f]/', $externalJobId) === 1) {
            throw new PortalException('INVALID_SIMULATOR_JOB_ID', 'The simulator job reference is invalid.', 400);
        }

        $transactionOpen = false;
        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Could not begin missing-callback recovery transaction.');
            }
            $transactionOpen = true;

            $job = $this->row('SELECT * FROM `sim_jobs` WHERE `id` = ? FOR UPDATE', [$externalJobId]);
            if ($job === null) {
                $this->rollback();
                $transactionOpen = false;
                throw new PortalException('SIMULATOR_JOB_NOT_FOUND', 'The simulator job was not found.', 404);
            }
            if ($job['selected_scenario'] !== 'missing' || $job['state'] !== 'succeeded') {
                $this->rollback();
                $transactionOpen = false;
                throw new PortalException('SIMULATOR_RECOVERY_NOT_APPLICABLE', 'Only a completed missing-callback scenario can be reconciled this way.', 409);
            }

            $original = $this->row(
                "SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ? AND `delivery_kind` = 'missing' AND `sequence_no` = 1 FOR UPDATE",
                [$externalJobId],
            );
            if ($original === null || $original['state'] !== 'suppressed') {
                $this->rollback();
                $transactionOpen = false;
                throw new PortalException('SIMULATOR_RECOVERY_NOT_READY', 'The original completion is not yet in the suppressed missing state.', 409);
            }

            $payload = self::decodeObject((string) $original['payload']);
            self::validateStoredMissingPayload($payload, $job, $original);
            $canonical = CanonicalJson::encode($payload);
            $fingerprint = hash('sha256', $canonical);
            if (! hash_equals((string) $original['payload_fingerprint'], $fingerprint)) {
                $this->rollback();
                $transactionOpen = false;
                throw new PortalException('SIMULATOR_RECOVERY_CONTENT_INVALID', 'The persisted completion does not match its stored integrity reference.', 409);
            }

            $existing = $this->row(
                "SELECT * FROM `sim_deliveries` WHERE `sim_job_id` = ? AND `delivery_kind` = 'recovery' AND `sequence_no` = 1 FOR UPDATE",
                [$externalJobId],
            );
            if ($existing !== null) {
                if (
                    $existing['event_id'] !== $original['event_id']
                    || ! hash_equals((string) $existing['payload_fingerprint'], $fingerprint)
                ) {
                    $this->rollback();
                    $transactionOpen = false;
                    throw new PortalException('SIMULATOR_RECOVERY_CONTENT_CONFLICT', 'An existing recovery reservation has different completion content.', 409);
                }
                if (! $this->db->transCommit()) {
                    throw new \RuntimeException('Could not commit recovery replay lookup.');
                }
                $transactionOpen = false;
                $this->log('sim.recovery.replayed', [
                    'job_id' => (string) $job['api_job_id'],
                    'event_id' => (string) $original['event_id'],
                    'outcome' => (string) $existing['state'],
                ]);
                return [
                    'external_job_id' => (string) $job['id'],
                    'event_id' => (string) $original['event_id'],
                    'delivery_id' => (string) $existing['id'],
                    'state' => (string) $existing['state'],
                    'replayed' => true,
                ];
            }

            $deliveryId = Identifiers::new('dlv');
            $now = $this->clock->utcNow()->setTimezone(new DateTimeZone('UTC'));
            $this->insert('sim_deliveries', [
                'id' => $deliveryId,
                'sim_job_id' => $job['id'],
                'delivery_kind' => 'recovery',
                'sequence_no' => 1,
                'event_id' => $original['event_id'],
                'payload_fingerprint' => $fingerprint,
                'payload' => $canonical,
                'due_at' => self::sqlTimestamp($now),
                'state' => 'pending',
                'delivery_generation' => 0,
                'lease_token' => null,
                'lease_expires_at' => null,
                'send_count' => 0,
            ]);
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new \RuntimeException('Could not commit missing-callback recovery reservation.');
            }
            $transactionOpen = false;

            $this->log('sim.recovery.scheduled', [
                'job_id' => (string) $job['api_job_id'],
                'event_id' => (string) $original['event_id'],
                'outcome' => 'pending',
            ]);

            return [
                'external_job_id' => (string) $job['id'],
                'event_id' => (string) $original['event_id'],
                'delivery_id' => $deliveryId,
                'state' => 'pending',
                'replayed' => false,
            ];
        } catch (PortalException $exception) {
            if ($transactionOpen) {
                $this->rollback();
            }
            throw $exception;
        } catch (Throwable) {
            if ($transactionOpen) {
                $this->rollback();
            }
            $this->log('sim.recovery.failed', ['error_code' => 'RECOVERY_RESERVATION_FAILURE']);
            throw new PortalException('SIMULATOR_RECOVERY_UNAVAILABLE', 'The simulator could not reserve the original completion.', 503);
        }
    }

    /** Process at most one claimed batch; network calls happen outside DB transactions. */
    public function tick(int $batchLimit = 20, int $leaseSeconds = 30): array
    {
        if ($batchLimit < 1 || $batchLimit > 100 || $leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new PortalException('INVALID_DISPATCH_LIMIT', 'The dispatcher batch or lease setting is invalid.', 400);
        }

        $summary = ['claimed' => 0, 'sent' => 0, 'suppressed' => 0, 'retrying' => 0, 'quarantined' => 0, 'failed' => 0, 'fenced' => 0];
        for ($index = 0; $index < $batchLimit; $index++) {
            $claim = $this->claimDueDelivery($leaseSeconds);
            if ($claim === null) {
                break;
            }
            $summary['claimed']++;
            if ($claim['kind'] === 'missing') {
                $summary['suppressed']++;
                continue;
            }

            $httpStatus = 0;
            try {
                $httpStatus = $this->sendCompletion($claim['payload']);
            } catch (Throwable) {
                $httpStatus = 0;
            }
            $result = $this->finishDelivery($claim, $httpStatus);
            $summary[$result]++;
        }

        return $summary;
    }

    private function acquireCapacityLock(): bool
    {
        $row = $this->row('SELECT GET_LOCK(?, 3) AS acquired', [self::CAPACITY_LOCK]);
        if ($row === null || (int) $row['acquired'] !== 1) {
            throw new PortalException('SIMULATOR_BUSY', 'The simulator request queue is busy.', 503);
        }
        return true;
    }

    private function releaseCapacityLock(): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [self::CAPACITY_LOCK]);
        } catch (Throwable) {
            $this->log('sim.capacity_lock.release_failed', ['error_code' => 'LOCK_RELEASE_FAILURE']);
        }
    }

    /** @param array<string, mixed> $command
     *  @param array<string, mixed> $profile
     */
    private function persistDeliveries(
        string $simJobId,
        array $command,
        string $externalVmId,
        string $scenario,
        string $digest,
        array $profile,
        DateTimeImmutable $dueAt,
    ): void {
        $eventId = Identifiers::new('evt');
        $errorCode = $scenario === 'failure' ? (new ScenarioSelector())->errorCode($digest, $profile['error_codes']) : null;
        $status = $scenario === 'failure' ? 'failed' : 'succeeded';
        $payload = [
            'event_id' => $eventId,
            'job_id' => $command['job_id'],
            'external_job_id' => $simJobId,
            'external_vm_id' => $externalVmId,
            'attempt' => $command['attempt'],
            'status' => $status,
            'observed_at' => self::wireTimestamp($dueAt),
            'error_code' => $errorCode,
            'mode' => 'simulated',
        ];

        if ($scenario === 'missing') {
            $this->insertDelivery($simJobId, 'missing', 1, $eventId, $payload, $dueAt);
            return;
        }

        $this->insertDelivery($simJobId, 'completion', 1, $eventId, $payload, $dueAt);
        if ($scenario === 'duplicate') {
            $duplicateAt = $dueAt->add(new DateInterval('PT' . $profile['duplicate_delay_seconds'] . 'S'));
            $this->insertDelivery($simJobId, 'completion', 2, $eventId, $payload, $duplicateAt);
        }
    }

    /** @param array<string, mixed> $payload */
    private function insertDelivery(string $simJobId, string $kind, int $sequence, string $eventId, array $payload, DateTimeImmutable $dueAt): void
    {
        $canonical = CanonicalJson::encode($payload);
        $this->insert('sim_deliveries', [
            'id' => Identifiers::new('dlv'),
            'sim_job_id' => $simJobId,
            'delivery_kind' => $kind,
            'sequence_no' => $sequence,
            'event_id' => $eventId,
            'payload_fingerprint' => hash('sha256', $canonical),
            'payload' => $canonical,
            'due_at' => self::sqlTimestamp($dueAt),
            'state' => 'pending',
            'delivery_generation' => 0,
            'lease_token' => null,
            'lease_expires_at' => null,
            'send_count' => 0,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function claimDueDelivery(int $leaseSeconds): ?array
    {
        $transactionOpen = false;
        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Could not begin delivery lease transaction.');
            }
            $transactionOpen = true;
            $now = $this->clock->utcNow()->setTimezone(new DateTimeZone('UTC'));
            $row = $this->row(
                "SELECT * FROM `sim_deliveries` WHERE (`state` = 'pending' AND `due_at` <= ?) OR (`state` = 'leased' AND `lease_expires_at` <= ?) ORDER BY `due_at`,`id` LIMIT 1 FOR UPDATE SKIP LOCKED",
                [self::sqlTimestamp($now), self::sqlTimestamp($now)],
            );
            if ($row === null) {
                $this->db->transCommit();
                return null;
            }

            $generation = (int) $row['delivery_generation'] + 1;
            $leaseToken = Identifiers::new('lsg');
            $leaseExpires = $now->add(new DateInterval('PT' . $leaseSeconds . 'S'));
            $this->update('sim_deliveries', [
                'state' => 'leased',
                'delivery_generation' => $generation,
                'lease_token' => $leaseToken,
                'lease_expires_at' => self::sqlTimestamp($leaseExpires),
            ], ['id' => $row['id']]);

            $payload = self::decodeObject((string) $row['payload']);
            $this->finalizeSimulationState($row, $payload);

            if ($row['delivery_kind'] === 'missing') {
                $this->update('sim_deliveries', [
                    'state' => 'suppressed',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ], ['id' => $row['id'], 'delivery_generation' => $generation]);
                if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                    throw new \RuntimeException('Could not commit missing-callback observation.');
                }
                return ['kind' => 'missing'];
            }

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new \RuntimeException('Could not commit delivery lease.');
            }

            $this->log('sim.delivery.leased', [
                'job_id' => (string) $row['sim_job_id'],
                'event_id' => (string) $row['event_id'],
                'attempt' => $generation,
                'outcome' => 'claimed',
            ]);

            return [
                'kind' => (string) $row['delivery_kind'],
                'id' => (string) $row['id'],
                'sim_job_id' => (string) $row['sim_job_id'],
                'event_id' => (string) $row['event_id'],
                'lease_token' => $leaseToken,
                'generation' => $generation,
                'payload' => $payload,
            ];
        } catch (Throwable) {
            if ($transactionOpen && $this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            $this->log('sim.delivery.claim_failed', ['error_code' => 'DELIVERY_CLAIM_FAILURE']);
            return null;
        }
    }

    /** @param array<string, mixed> $delivery
     *  @param array<string, mixed> $payload
     */
    private function finalizeSimulationState(array $delivery, array $payload): void
    {
        $job = $this->row('SELECT `state`,`selected_scenario`,`vm_id`,`due_at` FROM `sim_jobs` WHERE `id` = ? FOR UPDATE', [$delivery['sim_job_id']]);
        if ($job === null || $job['state'] !== 'accepted') {
            return;
        }

        $scenario = (string) $job['selected_scenario'];
        $status = $scenario === 'failure' ? 'failed' : 'succeeded';
        if (isset($payload['status']) && in_array($payload['status'], ['succeeded', 'failed'], true)) {
            $status = $payload['status'];
        }
        $observedAt = is_string($payload['observed_at'] ?? null)
            ? new DateTimeImmutable($payload['observed_at'])
            : self::parseSqlTimestamp((string) $job['due_at']);
        $this->update('sim_jobs', ['state' => $status], ['id' => $delivery['sim_job_id'], 'state' => 'accepted']);
        $this->update('sim_vms', [
            'state' => $status === 'succeeded' ? 'ready' : 'absent',
            'outcome' => $status,
            'observed_at' => self::sqlTimestamp($observedAt),
        ], ['api_vm_id' => $job['vm_id'], 'sim_job_id' => $delivery['sim_job_id']]);
    }

    /** @param array<string, mixed> $claim
     *  @return 'sent'|'retrying'|'quarantined'|'failed'|'fenced'
     */
    private function finishDelivery(array $claim, int $httpStatus): string
    {
        $transactionOpen = false;
        try {
            if (! $this->db->transBegin()) {
                throw new \RuntimeException('Could not begin delivery acknowledgement transaction.');
            }
            $transactionOpen = true;
            $row = $this->row('SELECT * FROM `sim_deliveries` WHERE `id` = ? FOR UPDATE', [$claim['id']]);
            if (
                $row === null
                || $row['state'] !== 'leased'
                || (int) $row['delivery_generation'] !== (int) $claim['generation']
                || ! hash_equals((string) $row['lease_token'], (string) $claim['lease_token'])
            ) {
                $this->db->transRollback();
                $this->log('sim.delivery.ack_fenced', ['job_id' => $claim['sim_job_id'], 'event_id' => $claim['event_id'], 'outcome' => 'stale_ack']);
                return 'fenced';
            }

            $sendCount = (int) $row['send_count'] + 1;
            $values = ['send_count' => $sendCount, 'lease_token' => null, 'lease_expires_at' => null];
            if ($httpStatus >= 200 && $httpStatus < 300) {
                $result = 'sent';
                $values['state'] = 'delivered';
            } elseif ($httpStatus === 409) {
                $result = 'quarantined';
                $values['state'] = 'quarantined';
            } elseif ($httpStatus >= 400 && $httpStatus < 500 && ! in_array($httpStatus, [408, 429], true)) {
                $result = 'failed';
                $values['state'] = 'failed';
            } elseif ($sendCount >= self::MAX_CALLBACK_ATTEMPTS) {
                $result = 'failed';
                $values['state'] = 'failed';
            } else {
                $result = 'retrying';
                $retrySeconds = min(self::MAX_RETRY_DELAY_SECONDS, 2 ** min($sendCount, 6));
                $retryAt = $this->clock->utcNow()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval('PT' . $retrySeconds . 'S'));
                $values['state'] = 'pending';
                $values['due_at'] = self::sqlTimestamp($retryAt);
            }

            $this->update('sim_deliveries', $values, [
                'id' => $claim['id'],
                'delivery_generation' => $claim['generation'],
                'lease_token' => $claim['lease_token'],
            ]);
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new \RuntimeException('Could not persist delivery acknowledgement.');
            }
            $transactionOpen = false;
            $this->log('sim.delivery.' . $result, [
                'job_id' => $claim['sim_job_id'],
                'event_id' => $claim['event_id'],
                'attempt' => $sendCount,
                'outcome' => $httpStatus === 409 ? 'content_conflict' : self::httpOutcome($httpStatus),
            ]);
            return $result;
        } catch (Throwable) {
            if ($transactionOpen && $this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            $this->log('sim.delivery.ack_failed', ['job_id' => $claim['sim_job_id'], 'event_id' => $claim['event_id'], 'error_code' => 'ACK_PERSISTENCE_FAILURE']);
            return 'retrying';
        }
    }

    /** @param array<string, mixed> $payload */
    private function sendCompletion(array $payload): int
    {
        if ($this->completionSender !== null) {
            return ($this->completionSender)($payload);
        }

        $config = $this->runtime();
        if (($config['mode'] ?? null) !== 'simulated' || (defined('ENVIRONMENT') && ENVIRONMENT !== 'development')) {
            throw new PortalException('SIMULATOR_CALLBACK_DISABLED', 'Simulator callbacks are available only in local development.', 503);
        }
        $base = self::loopbackApiUrl((string) ($config['api_url'] ?? ''));
        $secret = $config['simulator_secret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw new PortalException('SIMULATOR_CALLBACK_DISABLED', 'The simulator callback credential is unavailable.', 503);
        }
        if (! function_exists('curl_init')) {
            throw new PortalException('SIMULATOR_CALLBACK_DISABLED', 'The local callback transport is unavailable.', 503);
        }

        $handle = curl_init(rtrim($base, '/') . '/internal/v1/vmm-completions');
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize callback transport.');
        }
        try {
            $body = CanonicalJson::encode($payload);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Portal-Simulator: ' . $secret],
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($response === false) {
                return 0;
            }
            return $status;
        } finally {
            curl_close($handle);
        }
    }

    /** @param array<string, mixed> $command
     *  @param array<string, mixed> $profile
     */
    private function delayFor(string $scenario, string $digest, array $profile): int
    {
        if ($scenario === 'late') {
            return (new ScenarioSelector())->rangeValue($digest, 8, $profile['late_min_seconds'], $profile['late_max_seconds']);
        }
        return (new ScenarioSelector())->rangeValue($digest, 8, $profile['delay_min_seconds'], $profile['delay_max_seconds']);
    }

    /** @return array<string, mixed> */
    private function simulationConfig(): array
    {
        $config = $this->runtime();
        if (($config['mode'] ?? null) !== 'simulated' || ! isset($config['simulation']) || ! is_array($config['simulation'])) {
            throw new PortalException('SIMULATOR_DISABLED', 'The local simulated runtime is not configured.', 503);
        }
        return $config['simulation'];
    }

    /** @return array<string, mixed> */
    private function runtime(): array
    {
        return $this->runtimeConfig ??= Runtime::config();
    }

    private function forcedScenarioIsAllowed(): bool
    {
        return PHP_SAPI === 'cli'
            && defined('ENVIRONMENT')
            && ENVIRONMENT === 'development'
            && ($this->runtime()['mode'] ?? null) === 'simulated';
    }

    /** @param array<string, mixed> $job */
    private function receipt(array $job): array
    {
        return [
            'external_job_id' => (string) $job['id'],
            'external_vm_id' => self::externalVmId((string) $job['vm_id']),
            'status' => 'accepted',
            'observed_at' => self::wireTimestamp(self::parseSqlTimestamp((string) $job['created_at'])),
            'mode' => 'simulated',
        ];
    }

    private static function externalVmId(string $apiVmId): string
    {
        return 'simv_' . substr(hash('sha256', $apiVmId), 0, 48);
    }

    private static function loopbackApiUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            throw new PortalException('SIMULATOR_CALLBACK_DISABLED', 'The configured API callback target is not an approved loopback URL.', 503);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = ['127.0.0.1', 'localhost', '::1', '[::1]'];
        if (
            ($parts['scheme'] ?? null) !== 'http'
            || ! in_array($host, $allowed, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            throw new PortalException('SIMULATOR_CALLBACK_DISABLED', 'The configured API callback target is not an approved loopback URL.', 503);
        }
        return $baseUrl;
    }

    private function insert(string $table, array $data): void
    {
        if ($this->db->table($table)->insert($data) === false) {
            throw new \RuntimeException('Simulator insert failed.');
        }
    }

    private function update(string $table, array $data, array $where): void
    {
        if ($this->db->table($table)->where($where)->update($data) === false) {
            throw new \RuntimeException('Simulator update failed.');
        }
    }

    /** @param list<mixed> $binds
     *  @return array<string, mixed>|null
     */
    private function row(string $sql, array $binds = []): ?array
    {
        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new \RuntimeException('Simulator query failed.');
        }
        return $result->getRowArray() ?: null;
    }

    /** @param list<mixed> $binds
     *  @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new \RuntimeException('Simulator query failed.');
        }
        return $result->getResultArray();
    }

    private function rollback(): void
    {
        if ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }
    }

    private function log(string $event, array $context = []): void
    {
        $this->logger->write($event, $context);
    }

    private static function sqlTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function wireTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function parseSqlTimestamp(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \UnexpectedValueException('Stored simulator payload is not an object.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $payload
     *  @param array<string, mixed> $job
     *  @param array<string, mixed> $delivery
     */
    private static function validateStoredMissingPayload(array $payload, array $job, array $delivery): void
    {
        $fields = array_keys($payload);
        sort($fields);
        $expected = ['attempt','error_code','event_id','external_job_id','external_vm_id','job_id','mode','observed_at','status'];
        sort($expected);
        if (
            $fields !== $expected
            || ($payload['event_id'] ?? null) !== $delivery['event_id']
            || ($payload['job_id'] ?? null) !== $job['api_job_id']
            || ($payload['external_job_id'] ?? null) !== $job['id']
            || ($payload['external_vm_id'] ?? null) !== self::externalVmId((string) $job['vm_id'])
            || ($payload['attempt'] ?? null) !== (int) $job['attempt_no']
            || ($payload['status'] ?? null) !== 'succeeded'
            || ! array_key_exists('error_code', $payload)
            || $payload['error_code'] !== null
            || ($payload['mode'] ?? null) !== 'simulated'
            || ! is_string($payload['observed_at'] ?? null)
        ) {
            throw new PortalException('SIMULATOR_RECOVERY_CONTENT_INVALID', 'The persisted missing-callback payload is not a valid original completion.', 409);
        }
    }

    private static function httpOutcome(int $status): string
    {
        return match (true) {
            $status === 0 => 'transport_failure',
            $status >= 200 && $status < 300 => 'accepted',
            $status === 409 => 'content_conflict',
            $status >= 500 => 'upstream_failure',
            default => 'rejected',
        };
    }
}
