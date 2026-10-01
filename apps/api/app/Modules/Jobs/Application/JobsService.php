<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Application;

use App\Modules\Identity\Domain\ActorContext;
use App\Modules\Jobs\Domain\CreateRequest;
use App\Modules\Jobs\Domain\CreateRequestValidator;
use CodeIgniter\Database\BaseConnection;
use DateTimeZone;
use Portal\Shared\AuditSink;
use Portal\Shared\CanonicalJson;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\Identifiers;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class JobsService
{
    private const OPERATION = 'create';
    private const PROFILE_SNAPSHOT_KEYS = ['vcpus', 'memory_mb', 'disk_gb', 'network_ref'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditSink $audit,
        private readonly EventLogger $logger,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{job_id:string,vm_id:string,status:string,mode:string,request_id:string} */
    public function submit(ActorContext $actor, array $payload, string $idempotencyKey): array
    {
        try {
            $this->assertActor($actor);
            $request = (new CreateRequestValidator())->validate($payload, $idempotencyKey);
        } catch (PortalException $exception) {
            $this->recordDenied($actor, $exception);
            throw $exception;
        }
        $bodyFingerprint = $request->bodyFingerprint();
        $keyDigest = CanonicalJson::hash(['idempotency_key' => $request->idempotencyKey]);

        try {
            $existing = $this->findIdempotencyRequest($actor->userId, self::OPERATION, $keyDigest);
            if ($existing !== null) {
                return $this->resolveExisting($actor, $existing, $bodyFingerprint);
            }
        } catch (PortalException $exception) {
            $this->recordDenied($actor, $exception);
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable($actor, 'idempotency_lookup_failed');
        }

        $transactionOpen = false;
        $stage = 'quota_account_prepare';
        try {
            // Seed the per-actor lock row in autocommit before the business transaction.
            // Two new requests must not both hold duplicate-key insert locks and then upgrade them.
            $this->ensureQuotaAccount($actor->userId);

            $stage = 'transaction_begin';
            if (! $this->db->transBegin()) {
                throw $this->unavailable($actor, 'transaction_begin_failed');
            }
            $transactionOpen = true;

            $stage = 'quota_account_lock';
            $quota = $this->lockQuotaAccount($actor->userId);

            // The actor row serializes same-actor submissions before the unique idempotency claim.
            $existing = $this->findIdempotencyRequest($actor->userId, self::OPERATION, $keyDigest);
            if ($existing !== null) {
                $response = $this->resolveExisting($actor, $existing, $bodyFingerprint);
                $stage = 'idempotency_replay_commit';
                $this->commitOrFail('idempotency_replay_commit_failed');
                $transactionOpen = false;
                return $response;
            }

            $stage = 'profile_validate';
            $profile = $this->loadEnabledProfile($request->profileId);
            $stage = 'quota_check';
            $this->assertQuotaAvailable($quota);

            $now = $this->timestamp();
            $jobId = Identifiers::new('job');
            $vmId = Identifiers::new('vm');
            $attemptId = Identifiers::new('att');
            $outboxId = Identifiers::new('out');
            $reservationId = Identifiers::new('qrs');
            $externalKey = Identifiers::new('ext');
            $externalKeyRef = hash('sha256', $externalKey);

            $stage = 'idempotency_claim';
            $this->insertOrFail('idempotency_requests', [
                'actor_id' => $actor->userId,
                'operation' => self::OPERATION,
                'key_digest' => $keyDigest,
                'body_fingerprint' => $bodyFingerprint,
                'job_id' => null,
                'vm_id' => null,
                'created_at' => $now,
            ]);

            $stage = 'quota_reservation_counter';
            $this->incrementQuotaReservation($actor->userId, $now);
            $stage = 'vm_insert';
            $this->insertOrFail('vms', [
                'id' => $vmId,
                'owner_user_id' => $actor->userId,
                'profile_id' => $profile['profile_id'],
                'profile_version' => $profile['profile_version'],
                'profile_snapshot' => CanonicalJson::encode($profile['snapshot']),
                'mode' => $actor->mode,
                'lifecycle_state' => 'provisioning',
                'observed_power_state' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'observed_at' => null,
            ]);

            $stage = 'job_insert';
            $this->insertOrFail('jobs', [
                'id' => $jobId,
                'vm_id' => $vmId,
                'actor_id' => $actor->userId,
                'operation' => self::OPERATION,
                'state' => 'queued',
                'request_id' => $actor->requestId,
                'mode' => $actor->mode,
                'current_attempt_no' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'finished_at' => null,
            ]);

            $stage = 'quota_reservation_insert';
            $this->insertOrFail('quota_reservations', [
                'id' => $reservationId,
                'actor_id' => $actor->userId,
                'job_id' => $jobId,
                'state' => 'reserved',
                'policy_revision' => $quota['policy_revision'] ?? 'prototype-v1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $stage = 'attempt_insert';
            $this->insertOrFail('job_attempts', [
                'id' => $attemptId,
                'job_id' => $jobId,
                'attempt_no' => 1,
                'state' => 'queued',
                'external_job_id' => null,
                'external_vm_id' => null,
                'external_key_ref' => $externalKeyRef,
                'started_at' => null,
                'finished_at' => null,
                'observed_at' => null,
                'error_code' => null,
            ]);

            $command = [
                'version' => 'v1',
                'request_id' => $actor->requestId,
                'job_id' => $jobId,
                'vm_id' => $vmId,
                'operation' => self::OPERATION,
                'attempt' => 1,
                'external_idempotency_key' => $externalKey,
                'profile' => [
                    'profile_id' => $profile['profile_id'],
                    'profile_version' => $profile['profile_version'],
                    'snapshot' => $profile['snapshot'],
                ],
                'mode' => $actor->mode,
            ];
            $stage = 'outbox_insert';
            $this->insertOrFail('outbox', [
                'id' => $outboxId,
                'job_id' => $jobId,
                'attempt_id' => $attemptId,
                'command_version' => 'v1',
                'command_payload' => CanonicalJson::encode($command),
                'state' => 'queued',
                'available_at' => $now,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_generation' => 0,
                'lease_expires_at' => null,
                'delivery_count' => 0,
                'last_error_code' => null,
            ]);

            $stage = 'idempotency_complete';
            $updated = $this->db->table('idempotency_requests')
                ->where('actor_id', $actor->userId)
                ->where('operation', self::OPERATION)
                ->where('key_digest', $keyDigest)
                ->update(['job_id' => $jobId, 'vm_id' => $vmId]);
            if ($updated === false || $this->db->affectedRows() !== 1) {
                throw new \RuntimeException('Idempotency claim could not be completed.');
            }

            $stage = 'audit_append';
            $this->audit->append([
                'actor_id' => $actor->userId,
                'actor_kind' => $actor->actorKind,
                'target_type' => 'vm',
                'target_id' => $vmId,
                'action' => 'vm.create.request',
                'decision' => 'allowed',
                'reason' => 'request_accepted',
                'request_id' => $actor->requestId,
                'job_id' => $jobId,
                'attempt' => 1,
                'mode' => $actor->mode,
                'before' => null,
                'after' => [
                    'state' => 'queued',
                    'profile_id' => $profile['profile_id'],
                    'profile_version' => $profile['profile_version'],
                    'operation' => self::OPERATION,
                    'quota_state' => 'reserved',
                    'mode' => $actor->mode,
                ],
            ]);

            $stage = 'request_commit';
            $this->commitOrFail('request_commit_failed');
            $transactionOpen = false;
            $response = [
                'job_id' => $jobId,
                'vm_id' => $vmId,
                'status' => 'queued',
                'mode' => $actor->mode,
                'request_id' => $actor->requestId,
            ];

            $this->logger->write('vm_create_accepted', [
                'request_id' => $actor->requestId,
                'job_id' => $jobId,
                'attempt' => 1,
                'mode' => $actor->mode,
                'operation' => self::OPERATION,
                'outcome' => 'accepted',
            ]);

            return $response;
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }

            // A unique-key race can only be resolved after rollback; the winner owns the result.
            try {
                $existing = $this->findIdempotencyRequest($actor->userId, self::OPERATION, $keyDigest);
                if ($existing !== null) {
                    return $this->resolveExisting($actor, $existing, $bodyFingerprint);
                }
            } catch (Throwable $resolutionFailure) {
                if ($resolutionFailure instanceof PortalException && $resolutionFailure->errorCode === 'idempotency_conflict') {
                    $this->recordDenied($actor, $resolutionFailure);
                    throw $resolutionFailure;
                }
            }

            if ($exception instanceof PortalException) {
                if (in_array($exception->errorCode, ['quota_exceeded', 'profile_not_available', 'idempotency_conflict'], true)) {
                    $this->recordDenied($actor, $exception);
                }
                throw $exception;
            }

            $this->logger->write('vm_create_failed', [
                'request_id' => $actor->requestId,
                'mode' => $actor->mode,
                'operation' => self::OPERATION,
                'error_code' => 'request_unavailable',
                'reason' => 'stage_' . $stage,
                'outcome' => 'failed',
            ]);
            throw new PortalException('request_unavailable', 'The VM request could not be stored.', 503);
        }
    }

    /** @return list<array<string, mixed>> */
    public function listVms(ActorContext $actor): array
    {
        $this->assertActor($actor);
        $query = $this->db->table('vms')->select([
            'id', 'owner_user_id', 'profile_id', 'profile_version', 'profile_snapshot', 'mode',
            'lifecycle_state', 'observed_power_state', 'created_at', 'updated_at', 'observed_at',
        ]);
        if (! $actor->isInGroup('admin')) {
            $query->where('owner_user_id', $actor->userId);
        }

        $rows = $query->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        return array_map(fn (array $row): array => $this->vmView($row), $rows);
    }

    /** @return array<string, mixed> */
    public function getVm(ActorContext $actor, string $vmId): array
    {
        $this->assertActor($actor);
        $query = $this->db->table('vms')->select([
            'id', 'owner_user_id', 'profile_id', 'profile_version', 'profile_snapshot', 'mode',
            'lifecycle_state', 'observed_power_state', 'created_at', 'updated_at', 'observed_at',
        ])->where('id', $vmId);
        if (! $actor->isInGroup('admin')) {
            $query->where('owner_user_id', $actor->userId);
        }

        $row = $query->get()->getRowArray();
        if (! is_array($row)) {
            throw new PortalException('resource_not_found', 'The requested VM was not found.', 404);
        }

        return $this->vmView($row);
    }

    /** @return list<array<string, mixed>> */
    public function listJobs(ActorContext $actor): array
    {
        $this->assertActor($actor);
        $query = $this->db->table('jobs')->select([
            'id', 'vm_id', 'actor_id', 'operation', 'state', 'request_id', 'mode',
            'current_attempt_no', 'created_at', 'updated_at', 'finished_at',
        ]);
        if (! $actor->isInGroup('admin')) {
            $query->where('actor_id', $actor->userId);
        }

        $rows = $query->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        return array_map(fn (array $row): array => $this->jobView($row), $rows);
    }

    /** @return array<string, mixed> */
    public function getJob(ActorContext $actor, string $jobId): array
    {
        $this->assertActor($actor);
        $query = $this->db->table('jobs')->select([
            'id', 'vm_id', 'actor_id', 'operation', 'state', 'request_id', 'mode',
            'current_attempt_no', 'created_at', 'updated_at', 'finished_at',
        ])->where('id', $jobId);
        if (! $actor->isInGroup('admin')) {
            $query->where('actor_id', $actor->userId);
        }

        $row = $query->get()->getRowArray();
        if (! is_array($row)) {
            throw new PortalException('resource_not_found', 'The requested job was not found.', 404);
        }

        return $this->jobView($row);
    }

    private function assertActor(ActorContext $actor): void
    {
        if ($actor->actorKind !== 'user' || $actor->userId < 1 || ! $actor->isInGroup('member') && ! $actor->isInGroup('admin')) {
            throw new PortalException('authorization_denied', 'Access is not permitted.', 403);
        }

        if ($actor->mode !== 'simulated') {
            throw new PortalException('execution_mode_unavailable', 'This execution mode is unavailable.', 503);
        }

        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}\z/', $actor->requestId) !== 1) {
            throw new PortalException('invalid_request_context', 'The request context is invalid.', 400);
        }
    }

    private function ensureQuotaAccount(int $actorId): void
    {
        $config = Runtime::config();
        $limit = $config['active_vm_limit'] ?? null;
        if (! is_int($limit) || $limit < 1) {
            throw new PortalException('quota_policy_unavailable', 'The VM quota policy is unavailable.', 503);
        }

        $now = $this->timestamp();
        $result = $this->db->query(
            'INSERT IGNORE INTO `quota_accounts` (`actor_id`,`limit_count`,`reserved_count`,`consumed_count`,`policy_revision`,`updated_at`) VALUES (?,?,0,0,?,?)',
            [$actorId, $limit, 'prototype-v1', $now],
        );
        if ($result === false) {
            throw new \RuntimeException('Quota account could not be prepared.');
        }

    }

    /** @return array<string, mixed> */
    private function lockQuotaAccount(int $actorId): array
    {
        $result = $this->db->query(
            'SELECT `actor_id`,`limit_count`,`reserved_count`,`consumed_count`,`policy_revision` FROM `quota_accounts` WHERE `actor_id` = ? FOR UPDATE',
            [$actorId],
        );
        $row = $result === false ? null : $result->getRowArray();
        if (! is_array($row)) {
            throw new \RuntimeException('Quota account row is unavailable.');
        }

        return $row;
    }

    /** @param array<string, mixed> $quota */
    private function assertQuotaAvailable(array $quota): void
    {
        if ($quota['limit_count'] === null) {
            return;
        }

        $limit = (int) $quota['limit_count'];
        $inUse = (int) $quota['reserved_count'] + (int) $quota['consumed_count'];
        if ($limit < 0 || $inUse >= $limit) {
            throw new PortalException('quota_exceeded', 'The active VM quota has been reached.', 409);
        }
    }

    /** @return array{profile_id:string,profile_version:string,snapshot:array<string,mixed>} */
    private function loadEnabledProfile(string $profileId): array
    {
        $rows = $this->db->table('vm_profiles')
            ->select(['profile_id', 'profile_version', 'snapshot'])
            ->where('profile_id', $profileId)
            ->where('enabled', 1)
            ->get()
            ->getResultArray();

        if (count($rows) !== 1) {
            throw new PortalException('profile_not_available', 'The requested VM profile is unavailable.', 409);
        }

        $row = $rows[0];
        try {
            $snapshot = json_decode((string) $row['snapshot'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new PortalException('profile_not_available', 'The requested VM profile is unavailable.', 409);
        }

        if (! is_array($snapshot) || array_is_list($snapshot)) {
            throw new PortalException('profile_not_available', 'The requested VM profile is unavailable.', 409);
        }
        $keys = array_keys($snapshot);
        sort($keys);
        $expectedKeys = self::PROFILE_SNAPSHOT_KEYS;
        sort($expectedKeys);
        if ($keys !== $expectedKeys
            || ! is_int($snapshot['vcpus']) || $snapshot['vcpus'] < 1
            || ! is_int($snapshot['memory_mb']) || $snapshot['memory_mb'] < 1
            || ! is_int($snapshot['disk_gb']) || $snapshot['disk_gb'] < 1
            || ! is_string($snapshot['network_ref']) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/', $snapshot['network_ref']) !== 1) {
            throw new PortalException('profile_not_available', 'The requested VM profile is unavailable.', 409);
        }

        return [
            'profile_id' => (string) $row['profile_id'],
            'profile_version' => (string) $row['profile_version'],
            'snapshot' => $snapshot,
        ];
    }

    private function incrementQuotaReservation(int $actorId, string $now): void
    {
        $result = $this->db->query(
            'UPDATE `quota_accounts` SET `reserved_count` = `reserved_count` + 1, `updated_at` = ? WHERE `actor_id` = ?',
            [$now, $actorId],
        );
        if ($result === false || $this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Quota reservation could not be recorded.');
        }
    }

    private function insertOrFail(string $table, array $data): void
    {
        if ($this->db->table($table)->insert($data) === false) {
            throw new \RuntimeException('A request record could not be stored.');
        }
    }

    private function commitOrFail(string $errorCode): void
    {
        if (! $this->db->transStatus() || ! $this->db->transCommit()) {
            throw new PortalException($errorCode, 'The request could not be committed.', 503);
        }
    }

    /** @return array<string, mixed>|null */
    private function findIdempotencyRequest(int $actorId, string $operation, string $keyDigest): ?array
    {
        $row = $this->db->table('idempotency_requests')
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('key_digest', $keyDigest)
            ->get()
            ->getRowArray();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $existing
     *  @return array{job_id:string,vm_id:string,status:string,mode:string,request_id:string}
     */
    private function resolveExisting(ActorContext $actor, array $existing, string $bodyFingerprint): array
    {
        if (! hash_equals((string) $existing['body_fingerprint'], $bodyFingerprint)) {
            throw new PortalException('idempotency_conflict', 'The idempotency key was used with different input.', 409);
        }

        if (! is_string($existing['job_id']) || ! is_string($existing['vm_id'])) {
            throw new PortalException('request_unavailable', 'The prior request is incomplete.', 503);
        }

        $job = $this->db->table('jobs')
            ->select(['id', 'vm_id', 'state', 'mode'])
            ->where('id', $existing['job_id'])
            ->where('actor_id', $actor->userId)
            ->get()
            ->getRowArray();
        if (! is_array($job) || $job['vm_id'] !== $existing['vm_id']) {
            throw new PortalException('request_unavailable', 'The prior request is unavailable.', 503);
        }

        return [
            'job_id' => (string) $job['id'],
            'vm_id' => (string) $job['vm_id'],
            'status' => 'queued',
            'mode' => (string) $job['mode'],
            'request_id' => $actor->requestId,
        ];
    }

    private function recordDenied(ActorContext $actor, PortalException $exception): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}\z/', $actor->requestId) !== 1) {
            return;
        }

        $transactionOpen = false;
        try {
            if (! $this->db->transBegin()) {
                return;
            }
            $transactionOpen = true;
            $this->audit->append([
                'actor_id' => $actor->userId > 0 ? $actor->userId : null,
                'actor_kind' => preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,23}\z/', $actor->actorKind) === 1 ? $actor->actorKind : 'user',
                'target_type' => 'vm_request',
                'target_id' => null,
                'action' => 'vm.create.request',
                'decision' => 'denied',
                'reason' => preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}\z/', $exception->errorCode) === 1 ? $exception->errorCode : 'request_rejected',
                'request_id' => $actor->requestId,
                'job_id' => null,
                'attempt' => null,
                'mode' => $actor->mode === 'simulated' ? 'simulated' : 'unknown',
                'before' => null,
                'after' => ['result' => 'rejected', 'state' => 'denied'],
            ]);
            $this->commitOrFail('audit_write_failed');
            $transactionOpen = false;
        } catch (Throwable) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }
            $this->logger->write('vm_create_denial_audit_failed', [
                'request_id' => $actor->requestId,
                'mode' => $actor->mode,
                'operation' => self::OPERATION,
                'error_code' => 'audit_write_failed',
                'outcome' => 'failed',
            ]);
        }

        $this->logger->write('vm_create_rejected', [
            'request_id' => $actor->requestId,
            'mode' => $actor->mode,
            'operation' => self::OPERATION,
            'error_code' => $exception->errorCode,
            'outcome' => 'rejected',
        ]);
    }

    private function unavailable(ActorContext $actor, string $reason): PortalException
    {
        $this->logger->write('vm_create_failed', [
            'request_id' => $actor->requestId,
            'mode' => $actor->mode,
            'operation' => self::OPERATION,
            'error_code' => 'request_unavailable',
            'reason' => $reason,
            'outcome' => 'failed',
        ]);

        return new PortalException('request_unavailable', 'The VM request could not be stored.', 503);
    }

    private function timestamp(): string
    {
        return $this->clock->utcNow()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function vmView(array $row): array
    {
        return [
            'vm_id' => (string) $row['id'],
            'owner_user_id' => (int) $row['owner_user_id'],
            'profile_id' => (string) $row['profile_id'],
            'profile_version' => (string) $row['profile_version'],
            'profile' => $this->publicProfileSnapshot((string) $row['profile_snapshot']),
            'mode' => (string) $row['mode'],
            'lifecycle_state' => (string) $row['lifecycle_state'],
            'power_state' => $row['observed_power_state'] === null ? null : (string) $row['observed_power_state'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'observed_at' => $row['observed_at'] === null ? null : (string) $row['observed_at'],
            'latest_job' => $this->latestJobForVm((string) $row['id']),
        ];
    }

    /** @return array<string, int>|null */
    private function publicProfileSnapshot(string $encoded): ?array
    {
        try {
            $snapshot = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($snapshot)) {
            return null;
        }

        $public = [];
        foreach (['vcpus', 'memory_mb', 'disk_gb'] as $key) {
            if (isset($snapshot[$key]) && is_int($snapshot[$key])) {
                $public[$key] = $snapshot[$key];
            }
        }

        return $public;
    }

    /** @return array<string, mixed>|null */
    private function latestJobForVm(string $vmId): ?array
    {
        $row = $this->db->table('jobs')
            ->select(['id', 'operation', 'state', 'mode', 'current_attempt_no', 'created_at', 'updated_at', 'finished_at'])
            ->where('vm_id', $vmId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();

        if (! is_array($row)) {
            return null;
        }

        return [
            'job_id' => (string) $row['id'],
            'operation' => (string) $row['operation'],
            'state' => (string) $row['state'],
            'mode' => (string) $row['mode'],
            'current_attempt_no' => (int) $row['current_attempt_no'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
        ];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function jobView(array $row): array
    {
        $attempts = $this->db->table('job_attempts')
            ->select(['attempt_no', 'state', 'external_job_id', 'external_vm_id', 'started_at', 'finished_at', 'observed_at', 'error_code'])
            ->where('job_id', (string) $row['id'])
            ->orderBy('attempt_no', 'ASC')
            ->get()
            ->getResultArray();

        return [
            'job_id' => (string) $row['id'],
            'vm_id' => (string) $row['vm_id'],
            'operation' => (string) $row['operation'],
            'state' => (string) $row['state'],
            'request_id' => (string) $row['request_id'],
            'mode' => (string) $row['mode'],
            'current_attempt_no' => (int) $row['current_attempt_no'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
            'attempts' => array_map(static fn (array $attempt): array => [
                'attempt_no' => (int) $attempt['attempt_no'],
                'state' => (string) $attempt['state'],
                'external_job_id' => $attempt['external_job_id'] === null ? null : (string) $attempt['external_job_id'],
                'external_vm_id' => $attempt['external_vm_id'] === null ? null : (string) $attempt['external_vm_id'],
                'started_at' => $attempt['started_at'] === null ? null : (string) $attempt['started_at'],
                'finished_at' => $attempt['finished_at'] === null ? null : (string) $attempt['finished_at'],
                'observed_at' => $attempt['observed_at'] === null ? null : (string) $attempt['observed_at'],
                'error_code' => $attempt['error_code'] === null ? null : (string) $attempt['error_code'],
            ], $attempts),
        ];
    }
}
