<?php
declare(strict_types=1);

namespace Portal\Shared;

final class StructuredLogger implements EventLogger
{
    private const CONTEXT_KEYS = [
        'release', 'mode', 'request_id', 'job_id', 'event_id', 'attempt', 'duration_ms',
        'error_code', 'operation', 'outcome', 'reason', 'worker_id', 'service_role',
    ];

    private const LEVELS = ['INFO', 'WARN', 'ERROR'];

    private bool $degraded = false;

    public function __construct(
        private readonly string $service,
        private readonly ?string $path = null,
        private readonly ?Clock $clock = null,
    ) {
    }

    public function write(string $event, array $context = []): bool
    {
        $service = self::safeToken($this->service, 'unknown');
        $record = [
            'timestamp_utc' => ($this->clock ?? new SystemClock())->utcNow()
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => is_string($context['level'] ?? null) && in_array($context['level'], self::LEVELS, true)
                ? $context['level']
                : 'INFO',
            'service' => $service,
            'event' => self::safeToken($event, 'invalid_event'),
        ];

        foreach (self::CONTEXT_KEYS as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if ($key === 'attempt') {
                if (is_int($value) && $value >= 0) {
                    $record[$key] = $value;
                }
                continue;
            }
            if ($key === 'duration_ms') {
                if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0) {
                    $record[$key] = $value;
                }
                continue;
            }
            if (is_string($value)) {
                $record[$key] = self::safeToken($value, '[redacted]');
            }
        }

        try {
            $line = CanonicalJson::encode($record) . PHP_EOL;
            $written = $this->path === null
                ? self::writeStderr($line)
                : @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === strlen($line);
            $this->degraded = ! $written;
            if (! $written) {
                self::signalDegraded();
            }
            return $written;
        } catch (\Throwable) {
            $this->degraded = true;
            self::signalDegraded();
            return false;
        }
    }

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    private static function safeToken(string $value, string $fallback): string
    {
        if (strlen($value) > 128 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/', $value) !== 1) {
            return $fallback;
        }
        return $value;
    }

    private static function writeStderr(string $line): bool
    {
        return error_log(rtrim($line, "\r\n"));
    }

    private static function signalDegraded(): void
    {
        // Deliberately independent of the failing structured sink; do not recurse or include data.
        error_log('portal_logging_degraded');
    }
}
