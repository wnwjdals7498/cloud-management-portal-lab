<?php
declare(strict_types=1);

use CodeIgniter\Database\BaseConnection;
use Portal\Shared\AuditAppender;
use Portal\Shared\Identifiers;
use Portal\Shared\Runtime;
use Portal\Shared\SystemClock;
use PHPUnit\Framework\TestCase;

/** @internal Requires the four provisioned local MySQL schemas and app principals. */
final class P2FoundationMySqlTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $runtime;

    private BaseConnection $api;

    private BaseConnection $simulator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = Runtime::config();
        $this->api = self::connect(
            (string) $this->runtime['test_db'],
            (string) $this->runtime['test_user'],
            (string) $this->runtime['test_password'],
            (int) $this->runtime['mysql_port'],
        );
        $this->simulator = self::connect(
            (string) $this->runtime['sim_test_db'],
            (string) $this->runtime['sim_test_user'],
            (string) $this->runtime['sim_test_password'],
            (int) $this->runtime['mysql_port'],
        );
    }

    protected function tearDown(): void
    {
        $this->api->close();
        $this->simulator->close();
        parent::tearDown();
    }

    public function testConfiguredSchemasAndNativeSessionStructureAreTheTestTargets(): void
    {
        $apiSchema = self::scalar($this->api, 'SELECT DATABASE()');
        $simSchema = self::scalar($this->simulator, 'SELECT DATABASE()');

        $this->assertSame($this->runtime['test_db'], $apiSchema);
        $this->assertSame($this->runtime['sim_test_db'], $simSchema);
        $this->assertNotSame($this->runtime['api_db'], $apiSchema);
        $this->assertNotSame($this->runtime['sim_db'], $simSchema);
        $this->assertContains('jobs', $this->api->listTables());
        $this->assertContains('sim_jobs', $this->simulator->listTables());

        $columns = [];
        foreach (self::rows($this->api, 'SHOW COLUMNS FROM `ci_sessions`') as $row) {
            $columns[$row['Field']] = strtolower((string) $row['Type']);
        }
        $this->assertSame('varchar(128)', $columns['id'] ?? null);
        $this->assertSame('varchar(45)', $columns['ip_address'] ?? null);
        $this->assertStringStartsWith('timestamp', $columns['timestamp'] ?? '');
        $this->assertSame('blob', $columns['data'] ?? null);

        $idem = self::rows($this->api, "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'idempotency_requests' AND COLUMN_NAME IN ('job_id','vm_id')", [$apiSchema]);
        $nullable = array_column($idem, 'IS_NULLABLE', 'COLUMN_NAME');
        $this->assertSame('YES', $nullable['job_id'] ?? null);
        $this->assertSame('YES', $nullable['vm_id'] ?? null);

        $userType = self::scalar($this->api, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'", [$apiSchema]);
        $ownerType = self::scalar($this->api, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vms' AND COLUMN_NAME = 'owner_user_id'", [$apiSchema]);
        $this->assertSame('int unsigned', strtolower((string) $userType));
        $this->assertSame($userType, $ownerType);
    }

    public function testAppPrincipalsCannotReadTheOtherAppsSchema(): void
    {
        $apiDatabase = self::identifier((string) $this->runtime['test_db']);
        $simDatabase = self::identifier((string) $this->runtime['sim_test_db']);

        $apiResult = $this->api->query("SELECT COUNT(*) FROM `{$simDatabase}`.`sim_jobs`");
        $this->assertFalse($apiResult, 'API principal unexpectedly read the simulator schema.');
        $this->assertSame('permission_denied', self::classifyPermissionError($this->api->error()['code'] ?? null));

        $simResult = $this->simulator->query("SELECT COUNT(*) FROM `{$apiDatabase}`.`jobs`");
        $this->assertFalse($simResult, 'Simulator principal unexpectedly read the API schema.');
        $this->assertSame('permission_denied', self::classifyPermissionError($this->simulator->error()['code'] ?? null));
    }

    public function testNullableIdempotencyClaimHasForeignKeysAndEnforcesScopedUniqueness(): void
    {
        $schema = (string) $this->runtime['test_db'];
        $columns = self::rows($this->api, "SELECT COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'idempotency_requests' AND INDEX_NAME = 'uq_idempotency_scope_key' ORDER BY SEQ_IN_INDEX", [$schema]);
        $this->assertSame(['actor_id', 'operation', 'key_digest'], array_column($columns, 'COLUMN_NAME'));

        $references = self::rows($this->api, "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'idempotency_requests' AND COLUMN_NAME IN ('job_id','vm_id')", [$schema]);
        $foreignTargets = array_column($references, 'REFERENCED_TABLE_NAME', 'COLUMN_NAME');
        $this->assertSame('jobs', $foreignTargets['job_id'] ?? null);
        $this->assertSame('vms', $foreignTargets['vm_id'] ?? null);

        $actorId = (int) self::scalar($this->api, 'SELECT id FROM `users` ORDER BY id LIMIT 1');
        $keyDigest = hash('sha256', bin2hex(random_bytes(24)));
        $bodyFingerprint = hash('sha256', bin2hex(random_bytes(24)));
        $this->assertTrue($this->api->transBegin());
        try {
            $first = $this->api->table('idempotency_requests')->insert([
                'actor_id' => $actorId,
                'operation' => 'create',
                'key_digest' => $keyDigest,
                'body_fingerprint' => $bodyFingerprint,
                'job_id' => null,
                'vm_id' => null,
                'created_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
            $this->assertNotFalse($first, 'Nullable idempotency claim was rejected.');

            $duplicate = $this->api->table('idempotency_requests')->insert([
                'actor_id' => $actorId,
                'operation' => 'create',
                'key_digest' => $keyDigest,
                'body_fingerprint' => $bodyFingerprint,
                'job_id' => null,
                'vm_id' => null,
                'created_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
            $this->assertFalse($duplicate, 'Duplicate actor/operation/key scope was accepted.');
            $this->assertFalse($this->api->transStatus(), 'Duplicate unique key did not fail the transaction.');
            $this->assertTrue($this->api->transRollback());
        } catch (Throwable) {
            if ($this->api->transDepth > 0) {
                $this->api->transRollback();
            }
            throw new RuntimeException('P2 idempotency constraint evidence failed; database diagnostics suppressed.', 0);
        }
    }

    public function testCallerTransactionCommitsAuditAndWorkAndRollsBothBackOnFailure(): void
    {
        $actorId = self::scalar($this->api, 'SELECT id FROM `users` ORDER BY id LIMIT 1');
        $this->assertNotNull($actorId, 'Test API schema has no seeded Shield user.');
        $actorId = (int) $actorId;

        $committed = $this->insertVmJobAndAudit($actorId);
        try {
            $this->assertSame(1, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `vms` WHERE id = ?', [$committed['vm_id']]));
            $this->assertSame(1, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `jobs` WHERE id = ?', [$committed['job_id']]));
            $this->assertSame(1, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `audit_events` WHERE event_id = ?', [$committed['audit_id']]));

            $update = $this->api->query('UPDATE `audit_events` SET `decision` = ? WHERE `event_id` = ?', ['tampered', $committed['audit_id']]);
            $this->assertFalse($update, 'API principal unexpectedly updated an audit event.');
            $this->assertSame('permission_denied', self::classifyPermissionError($this->api->error()['code'] ?? null));
            $delete = $this->api->query('DELETE FROM `audit_events` WHERE `event_id` = ?', [$committed['audit_id']]);
            $this->assertFalse($delete, 'API principal unexpectedly deleted an audit event.');
            $this->assertSame('permission_denied', self::classifyPermissionError($this->api->error()['code'] ?? null));
            $this->assertSame('allowed', self::scalar($this->api, 'SELECT `decision` FROM `audit_events` WHERE `event_id` = ?', [$committed['audit_id']]));

            $rolledBack = [
                'vm_id' => Identifiers::new('vmt'),
                'job_id' => Identifiers::new('job'),
                'request_id' => Identifiers::new('req'),
                'audit_id' => null,
            ];
            $this->assertTrue($this->api->transBegin());
            try {
                self::insertVm($this->api, $rolledBack['vm_id'], $actorId);
                self::insertJob($this->api, $rolledBack['job_id'], $rolledBack['vm_id'], $actorId, $rolledBack['request_id']);
                $rolledBack['audit_id'] = self::appendAudit($this->api, $actorId, $rolledBack['job_id'], $rolledBack['request_id']);

                // A missing job FK deterministically fails after work and audit inserts in this transaction.
                $bad = $this->api->table('quota_reservations')->insert([
                    'id' => Identifiers::new('qrs'),
                    'actor_id' => $actorId,
                    'job_id' => Identifiers::new('job'),
                    'state' => 'reserved',
                    'policy_revision' => 'p2-test',
                    'created_at' => gmdate('Y-m-d H:i:s.u'),
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ]);
                $this->assertFalse($bad, 'Invalid quota reservation unexpectedly passed its FK.');
                $this->assertFalse($this->api->transStatus(), 'Failed FK statement did not mark the transaction failed.');
                $this->assertTrue($this->api->transRollback());
            } catch (Throwable) {
                if ($this->api->transDepth > 0) {
                    $this->api->transRollback();
                }
                throw new RuntimeException('P2 transaction evidence failed; database diagnostics suppressed.', 0);
            }

            $this->assertSame(0, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `vms` WHERE id = ?', [$rolledBack['vm_id']]));
            $this->assertSame(0, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `jobs` WHERE id = ?', [$rolledBack['job_id']]));
            $this->assertSame(0, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `audit_events` WHERE event_id = ?', [$rolledBack['audit_id']]));
        } catch (Throwable $error) {
            throw $error;
        } finally {
            $this->api->table('jobs')->where('id', $committed['job_id'])->delete();
            $this->api->table('vms')->where('id', $committed['vm_id'])->delete();
        }
        $this->assertSame(0, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `jobs` WHERE id = ?', [$committed['job_id']]));
        $this->assertSame(0, (int) self::scalar($this->api, 'SELECT COUNT(*) FROM `vms` WHERE id = ?', [$committed['vm_id']]));
    }

    /** @return array{vm_id: string, job_id: string, request_id: string, audit_id: string} */
    private function insertVmJobAndAudit(int $actorId): array
    {
        $record = [
            'vm_id' => Identifiers::new('vmt'),
            'job_id' => Identifiers::new('job'),
            'request_id' => Identifiers::new('req'),
            'audit_id' => '',
        ];
        $this->assertTrue($this->api->transBegin());
        try {
            self::insertVm($this->api, $record['vm_id'], $actorId);
            self::insertJob($this->api, $record['job_id'], $record['vm_id'], $actorId, $record['request_id']);
            $record['audit_id'] = self::appendAudit($this->api, $actorId, $record['job_id'], $record['request_id']);
            $this->assertTrue($this->api->transStatus());
            $this->assertTrue($this->api->transCommit());
        } catch (Throwable) {
            if ($this->api->transDepth > 0) {
                $this->api->transRollback();
            }
            throw new RuntimeException('P2 commit evidence failed; database diagnostics suppressed.', 0);
        }

        return $record;
    }

    private static function insertVm(BaseConnection $db, string $vmId, int $actorId): void
    {
        $ok = $db->table('vms')->insert([
            'id' => $vmId,
            'owner_user_id' => $actorId,
            'profile_id' => 'sim-basic-1',
            'profile_version' => 'v1',
            'profile_snapshot' => '{"test_only":true}',
            'mode' => 'simulated',
            'lifecycle_state' => 'provisioning',
            'observed_power_state' => null,
            'created_at' => gmdate('Y-m-d H:i:s.u'),
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
            'observed_at' => null,
        ]);
        if ($ok === false) {
            throw new RuntimeException('P2 business insert failed; database diagnostics suppressed.', 0);
        }
    }

    private static function insertJob(BaseConnection $db, string $jobId, string $vmId, int $actorId, string $requestId): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $db->table('jobs')->insert([
            'id' => $jobId,
            'vm_id' => $vmId,
            'actor_id' => $actorId,
            'operation' => 'create',
            'state' => 'queued',
            'request_id' => $requestId,
            'mode' => 'simulated',
            'current_attempt_no' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ]);
        if ($ok === false) {
            throw new RuntimeException('P2 work insert failed; database diagnostics suppressed.', 0);
        }
    }

    private static function appendAudit(BaseConnection $db, int $actorId, string $jobId, string $requestId): string
    {
        return (new AuditAppender($db, new SystemClock()))->append([
            'actor_id' => $actorId,
            'actor_kind' => 'user',
            'target_type' => 'job',
            'target_id' => $jobId,
            'action' => 'create',
            'decision' => 'allowed',
            'reason' => 'p2_transaction_test',
            'request_id' => $requestId,
            'job_id' => $jobId,
            'attempt' => 1,
            'mode' => 'simulated',
            'before' => ['state' => 'absent'],
            'after' => ['state' => 'queued'],
        ]);
    }

    /** @param list<mixed> $binds */
    private static function scalar(BaseConnection $db, string $sql, array $binds = []): mixed
    {
        $result = $db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('P2 read failed; database diagnostics suppressed.', 0);
        }
        $row = $result->getRowArray();
        return $row === null ? null : array_values($row)[0];
    }

    /** @param list<mixed> $binds
     *  @return list<array<string, mixed>>
     */
    private static function rows(BaseConnection $db, string $sql, array $binds = []): array
    {
        $result = $db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('P2 metadata read failed; database diagnostics suppressed.', 0);
        }
        return $result->getResultArray();
    }

    private static function connect(string $database, string $username, string $password, int $port): BaseConnection
    {
        $params = [
            'DSN' => '',
            'hostname' => '127.0.0.1',
            'username' => $username,
            'password' => $password,
            'database' => self::identifier($database),
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => false,
            'charset' => 'utf8mb4',
            'DBCollat' => 'utf8mb4_unicode_ci',
            'strictOn' => true,
            'port' => $port,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s.u', 'time' => 'H:i:s'],
        ];

        try {
            $db = Config\Database::connect($params, false);
            $db->initialize();
            if ($db->query('SELECT DATABASE()') === false) {
                throw new RuntimeException('connection unavailable');
            }
            return $db;
        } catch (Throwable) {
            throw new RuntimeException('Configured test database is unavailable; diagnostics suppressed.', 0);
        }
    }

    private static function identifier(string $value): string
    {
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $value) !== 1) {
            throw new RuntimeException('Configured test schema name is invalid.');
        }
        return $value;
    }

    private static function classifyPermissionError(mixed $code): string
    {
        return in_array((int) $code, [1044, 1045, 1142, 1143], true) ? 'permission_denied' : 'unexpected_error';
    }
}
