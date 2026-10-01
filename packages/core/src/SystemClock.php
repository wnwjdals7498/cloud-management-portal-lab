<?php
declare(strict_types=1);

namespace Portal\Shared;

final class SystemClock implements Clock
{
    public function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
