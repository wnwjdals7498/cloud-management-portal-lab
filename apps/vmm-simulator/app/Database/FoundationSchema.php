<?php
declare(strict_types=1);

namespace App\Database;

/** Emits the isolated simulator schema; it never connects to or changes a database. */
final class FoundationSchema
{
    /** @return list<string> */
    public static function statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `sim_jobs` (
                `id` VARCHAR(64) NOT NULL,
                `api_job_id` VARCHAR(64) NOT NULL,
                `attempt_no` INT UNSIGNED NOT NULL DEFAULT 1,
                `vm_id` VARCHAR(64) NOT NULL,
                `external_key_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `command_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `command_payload` JSON NOT NULL,
                `mode` VARCHAR(16) NOT NULL,
                `profile_version` VARCHAR(32) NOT NULL,
                `profile_snapshot` JSON NOT NULL,
                `seed_ref` VARCHAR(64) NOT NULL,
                `draw_algorithm` VARCHAR(32) NOT NULL,
                `selected_scenario` VARCHAR(24) NOT NULL,
                `state` VARCHAR(24) NOT NULL,
                `due_at` DATETIME(6) NULL,
                `created_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sim_api_attempt` (`api_job_id`, `attempt_no`),
                UNIQUE KEY `uq_sim_external_key` (`external_key_digest`),
                KEY `idx_sim_vm` (`vm_id`),
                KEY `idx_sim_due` (`state`, `due_at`),
                CONSTRAINT `ck_sim_mode` CHECK (`mode` = 'simulated')
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `sim_vms` (
                `id` VARCHAR(64) NOT NULL,
                `sim_job_id` VARCHAR(64) NOT NULL,
                `api_vm_id` VARCHAR(64) NOT NULL,
                `state` VARCHAR(24) NOT NULL,
                `outcome` VARCHAR(24) NOT NULL,
                `observed_at` DATETIME(6) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sim_vm_api_vm` (`api_vm_id`),
                KEY `idx_sim_vm_job` (`sim_job_id`),
                CONSTRAINT `fk_sim_vm_job` FOREIGN KEY (`sim_job_id`) REFERENCES `sim_jobs` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `sim_deliveries` (
                `id` VARCHAR(64) NOT NULL,
                `sim_job_id` VARCHAR(64) NOT NULL,
                `delivery_kind` VARCHAR(24) NOT NULL,
                `sequence_no` INT UNSIGNED NOT NULL,
                `event_id` VARCHAR(64) NOT NULL,
                `payload_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `payload` JSON NOT NULL,
                `due_at` DATETIME(6) NOT NULL,
                `state` VARCHAR(24) NOT NULL,
                `delivery_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_token` CHAR(64) NULL,
                `lease_expires_at` DATETIME(6) NULL,
                `send_count` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sim_delivery_sequence` (`sim_job_id`, `delivery_kind`, `sequence_no`),
                KEY `idx_sim_delivery_event` (`event_id`),
                KEY `idx_sim_delivery_due` (`state`, `due_at`, `lease_expires_at`),
                CONSTRAINT `fk_sim_delivery_job` FOREIGN KEY (`sim_job_id`) REFERENCES `sim_jobs` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
        ];
    }
}
