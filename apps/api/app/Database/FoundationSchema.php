<?php
declare(strict_types=1);

namespace App\Database;

/**
 * Emits the initial 업무 schema for root-owned migration review/application.
 * Shield tables and CodeIgniter's native Session DatabaseHandler schema are
 * provisioned by their own migrations. This class never connects to or changes a database.
 */
final class FoundationSchema
{
    /** @return list<string> */
    public static function statements(string $usersTable = 'users'): array
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $usersTable) !== 1) {
            throw new \InvalidArgumentException('Shield users table name is invalid.');
        }

        $users = '`' . $usersTable . '`';

        return [
            "CREATE TABLE IF NOT EXISTS `vm_profiles` (
                `profile_id` VARCHAR(64) NOT NULL,
                `profile_version` VARCHAR(32) NOT NULL,
                `snapshot` JSON NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`profile_id`, `profile_version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `vms` (
                `id` VARCHAR(64) NOT NULL,
                `owner_user_id` INT UNSIGNED NOT NULL,
                `profile_id` VARCHAR(64) NOT NULL,
                `profile_version` VARCHAR(32) NOT NULL,
                `profile_snapshot` JSON NOT NULL,
                `mode` VARCHAR(16) NOT NULL,
                `lifecycle_state` VARCHAR(32) NOT NULL,
                `observed_power_state` VARCHAR(32) NULL,
                `created_at` DATETIME(6) NOT NULL,
                `updated_at` DATETIME(6) NOT NULL,
                `observed_at` DATETIME(6) NULL,
                PRIMARY KEY (`id`),
                KEY `idx_vms_owner_state` (`owner_user_id`, `lifecycle_state`),
                CONSTRAINT `fk_vms_owner` FOREIGN KEY (`owner_user_id`) REFERENCES {$users} (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `jobs` (
                `id` VARCHAR(64) NOT NULL,
                `vm_id` VARCHAR(64) NOT NULL,
                `actor_id` INT UNSIGNED NOT NULL,
                `operation` VARCHAR(32) NOT NULL,
                `state` VARCHAR(32) NOT NULL,
                `request_id` VARCHAR(64) NOT NULL,
                `mode` VARCHAR(16) NOT NULL,
                `current_attempt_no` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` DATETIME(6) NOT NULL,
                `updated_at` DATETIME(6) NOT NULL,
                `finished_at` DATETIME(6) NULL,
                PRIMARY KEY (`id`),
                KEY `idx_jobs_actor_state` (`actor_id`, `state`),
                KEY `idx_jobs_state_updated` (`state`, `updated_at`),
                CONSTRAINT `fk_jobs_vm` FOREIGN KEY (`vm_id`) REFERENCES `vms` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jobs_actor` FOREIGN KEY (`actor_id`) REFERENCES {$users} (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `idempotency_requests` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `actor_id` INT UNSIGNED NOT NULL,
                `operation` VARCHAR(32) NOT NULL,
                `key_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `body_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `job_id` VARCHAR(64) NULL,
                `vm_id` VARCHAR(64) NULL,
                `created_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_idempotency_scope_key` (`actor_id`, `operation`, `key_digest`),
                KEY `idx_idempotency_job` (`job_id`),
                CONSTRAINT `fk_idempotency_actor` FOREIGN KEY (`actor_id`) REFERENCES {$users} (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_idempotency_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_idempotency_vm` FOREIGN KEY (`vm_id`) REFERENCES `vms` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `quota_accounts` (
                `actor_id` INT UNSIGNED NOT NULL,
                `limit_count` INT UNSIGNED NULL,
                `reserved_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `consumed_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `policy_revision` VARCHAR(32) NULL,
                `updated_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`actor_id`),
                CONSTRAINT `fk_quota_actor` FOREIGN KEY (`actor_id`) REFERENCES {$users} (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `quota_reservations` (
                `id` VARCHAR(64) NOT NULL,
                `actor_id` INT UNSIGNED NOT NULL,
                `job_id` VARCHAR(64) NOT NULL,
                `state` VARCHAR(16) NOT NULL,
                `policy_revision` VARCHAR(32) NULL,
                `created_at` DATETIME(6) NOT NULL,
                `updated_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_quota_reservation_job` (`job_id`),
                KEY `idx_quota_reservation_actor_state` (`actor_id`, `state`),
                CONSTRAINT `fk_quota_reservation_actor` FOREIGN KEY (`actor_id`) REFERENCES {$users} (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_quota_reservation_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `job_attempts` (
                `id` VARCHAR(64) NOT NULL,
                `job_id` VARCHAR(64) NOT NULL,
                `attempt_no` INT UNSIGNED NOT NULL,
                `state` VARCHAR(32) NOT NULL,
                `external_job_id` VARCHAR(128) NULL,
                `external_vm_id` VARCHAR(128) NULL,
                `external_key_ref` VARCHAR(64) NOT NULL,
                `started_at` DATETIME(6) NULL,
                `finished_at` DATETIME(6) NULL,
                `observed_at` DATETIME(6) NULL,
                `error_code` VARCHAR(64) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_job_attempt_number` (`job_id`, `attempt_no`),
                CONSTRAINT `fk_job_attempt_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `outbox` (
                `id` VARCHAR(64) NOT NULL,
                `job_id` VARCHAR(64) NOT NULL,
                `attempt_id` VARCHAR(64) NOT NULL,
                `command_version` VARCHAR(16) NOT NULL,
                `command_payload` JSON NOT NULL,
                `state` VARCHAR(24) NOT NULL,
                `available_at` DATETIME(6) NOT NULL,
                `lease_owner` VARCHAR(64) NULL,
                `lease_token` CHAR(64) NULL,
                `lease_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_expires_at` DATETIME(6) NULL,
                `delivery_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_error_code` VARCHAR(64) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_outbox_attempt` (`attempt_id`),
                KEY `idx_outbox_due` (`state`, `available_at`, `lease_expires_at`),
                CONSTRAINT `fk_outbox_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_outbox_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `job_attempts` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `callback_inbox` (
                `event_id` VARCHAR(64) NOT NULL,
                `payload_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `canonical_payload` JSON NOT NULL,
                `job_id` VARCHAR(64) NULL,
                `attempt_no` INT UNSIGNED NULL,
                `external_job_id` VARCHAR(128) NOT NULL,
                `external_vm_id` VARCHAR(128) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `observed_at` DATETIME(6) NOT NULL,
                `error_code` VARCHAR(64) NULL,
                `mode` VARCHAR(16) NOT NULL,
                `state` VARCHAR(24) NOT NULL,
                `received_at` DATETIME(6) NOT NULL,
                `processed_at` DATETIME(6) NULL,
                `quarantine_reason` VARCHAR(64) NULL,
                PRIMARY KEY (`event_id`),
                KEY `idx_inbox_state_received` (`state`, `received_at`),
                KEY `idx_inbox_job_attempt` (`job_id`, `attempt_no`),
                CONSTRAINT `fk_inbox_job_attempt` FOREIGN KEY (`job_id`, `attempt_no`) REFERENCES `job_attempts` (`job_id`, `attempt_no`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `callback_conflicts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id` VARCHAR(64) NOT NULL,
                `payload_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `request_id` VARCHAR(64) NULL,
                `reason` VARCHAR(64) NOT NULL,
                `received_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_callback_conflict_event` (`event_id`, `received_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE IF NOT EXISTS `audit_events` (
                `event_id` VARCHAR(64) NOT NULL,
                `actor_id` INT UNSIGNED NULL,
                `actor_kind` VARCHAR(24) NOT NULL,
                `target_type` VARCHAR(48) NOT NULL,
                `target_id` VARCHAR(64) NULL,
                `action` VARCHAR(64) NOT NULL,
                `decision` VARCHAR(24) NOT NULL,
                `reason` VARCHAR(64) NULL,
                `request_id` VARCHAR(64) NULL,
                `job_id` VARCHAR(64) NULL,
                `attempt` INT UNSIGNED NULL,
                `mode` VARCHAR(16) NOT NULL,
                `before_summary` JSON NULL,
                `after_summary` JSON NULL,
                `occurred_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `idx_audit_actor_time` (`actor_id`, `occurred_at`),
                KEY `idx_audit_target_time` (`target_type`, `target_id`, `occurred_at`),
                KEY `idx_audit_request` (`request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
        ];
    }
}
