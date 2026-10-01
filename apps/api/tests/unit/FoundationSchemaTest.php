<?php
declare(strict_types=1);

use App\Database\FoundationSchema;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FoundationSchemaTest extends CIUnitTestCase
{
    public function testSchemaEmitsTransactionalBusinessAndAppendOnlyAuditRelations(): void
    {
        $statements = FoundationSchema::statements();
        $sql = implode("\n", $statements);

        foreach (['vms', 'jobs', 'idempotency_requests', 'quota_accounts', 'quota_reservations', 'job_attempts', 'outbox', 'callback_inbox', 'callback_conflicts', 'audit_events'] as $table) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $sql);
        }
        $this->assertStringContainsString('`owner_user_id` INT UNSIGNED', $sql);
        $this->assertStringContainsString('`job_id` VARCHAR(64) NULL', $sql);
        $this->assertStringContainsString('`vm_id` VARCHAR(64) NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uq_idempotency_scope_key` (`actor_id`, `operation`, `key_digest`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uq_job_attempt_number` (`job_id`, `attempt_no`)', $sql);
        $this->assertStringContainsString('`lease_generation` BIGINT UNSIGNED', $sql);
        $this->assertStringContainsString('`payload_fingerprint` CHAR(64)', $sql);
        $this->assertStringNotContainsString('ON DELETE CASCADE', $sql);
    }

    public function testSchemaRejectsUnsafeShieldUserTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FoundationSchema::statements('users; DROP TABLE users');
    }
}
