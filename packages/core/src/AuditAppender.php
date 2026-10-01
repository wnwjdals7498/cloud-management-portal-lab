<?php
declare(strict_types=1);

namespace Portal\Shared;

use CodeIgniter\Database\BaseConnection;

final class AuditAppender implements AuditSink
{
    private const EVENT_KEYS = [
        'actor_id', 'actor_kind', 'target_type', 'target_id', 'action', 'decision', 'reason',
        'request_id', 'job_id', 'attempt', 'mode', 'before', 'after',
    ];

    private const SUMMARY_KEYS = [
        'state', 'profile_id', 'profile_version', 'mode', 'operation', 'status',
        'power_state', 'quota_state', 'result', 'revision', 'count',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    public function append(array $event): string
    {
        $unknown = array_diff(array_keys($event), self::EVENT_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Audit event contains unsupported fields.');
        }

        $requiredLengths = [
            'actor_kind' => 24,
            'target_type' => 48,
            'action' => 64,
            'decision' => 24,
            'mode' => 16,
        ];
        foreach ($requiredLengths as $required => $maxLength) {
            if (! isset($event[$required]) || ! is_string($event[$required]) || ! self::isToken($event[$required], $maxLength)) {
                throw new \InvalidArgumentException('Audit event is missing a valid required field.');
            }
        }

        $actorId = $event['actor_id'] ?? null;
        if ($actorId !== null && (! is_int($actorId) || $actorId < 1)) {
            throw new \InvalidArgumentException('Audit actor reference is invalid.');
        }

        if (isset($event['attempt']) && (! is_int($event['attempt']) || $event['attempt'] < 1)) {
            throw new \InvalidArgumentException('Audit attempt is invalid.');
        }

        foreach (['target_id' => 64, 'request_id' => 64, 'job_id' => 64, 'reason' => 64] as $key => $maxLength) {
            if (isset($event[$key]) && (! is_string($event[$key]) || ! self::isToken($event[$key], $maxLength))) {
                throw new \InvalidArgumentException('Audit reference or reason is invalid.');
            }
        }

        $id = Identifiers::new('aud');
        $record = [
            'event_id'        => $id,
            'actor_id'        => $actorId,
            'actor_kind'      => $event['actor_kind'],
            'target_type'     => $event['target_type'],
            'target_id'       => $event['target_id'] ?? null,
            'action'          => $event['action'],
            'decision'        => $event['decision'],
            'reason'          => $event['reason'] ?? null,
            'request_id'      => $event['request_id'] ?? null,
            'job_id'          => $event['job_id'] ?? null,
            'attempt'         => $event['attempt'] ?? null,
            'mode'            => $event['mode'],
            'before_summary'  => isset($event['before']) ? CanonicalJson::encode(self::safeSummary($event['before'])) : null,
            'after_summary'   => isset($event['after']) ? CanonicalJson::encode(self::safeSummary($event['after'])) : null,
            'occurred_at'     => $this->clock->utcNow()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ];

        if ($this->db->table('audit_events')->insert($record) === false) {
            throw new \RuntimeException('Audit event could not be persisted.');
        }

        return $id;
    }

    private static function safeSummary(mixed $summary): array
    {
        if (! is_array($summary)) {
            throw new \InvalidArgumentException('Audit summary must be an array.');
        }

        $safe = [];
        foreach ($summary as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::SUMMARY_KEYS, true)) {
                continue;
            }

            if (is_string($value) && self::isToken($value, 64)) {
                $safe[$key] = $value;
            } elseif (is_int($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private static function isToken(string $value, int $maxLength): bool
    {
        return strlen($value) <= $maxLength && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/', $value) === 1;
    }
}
