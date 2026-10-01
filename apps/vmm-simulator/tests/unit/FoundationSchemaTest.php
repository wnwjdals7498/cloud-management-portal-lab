<?php
declare(strict_types=1);

use App\Database\FoundationSchema;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FoundationSchemaTest extends CIUnitTestCase
{
    public function testSimulatorSchemaKeepsIdempotencyDrawAndDeliveryQueueIsolated(): void
    {
        $sql = implode("\n", FoundationSchema::statements());

        foreach (['sim_jobs', 'sim_vms', 'sim_deliveries'] as $table) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $sql);
        }
        $this->assertStringContainsString('UNIQUE KEY `uq_sim_api_attempt` (`api_job_id`, `attempt_no`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uq_sim_external_key` (`external_key_digest`)', $sql);
        $this->assertStringContainsString('`command_fingerprint` CHAR(64)', $sql);
        $this->assertStringContainsString('`command_payload` JSON', $sql);
        $this->assertStringContainsString('KEY `idx_sim_delivery_event` (`event_id`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uq_sim_delivery_sequence` (`sim_job_id`, `delivery_kind`, `sequence_no`)', $sql);
        $this->assertStringContainsString("CHECK (`mode` = 'simulated')", $sql);
    }
}
