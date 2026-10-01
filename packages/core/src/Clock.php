<?php
declare(strict_types=1);
namespace Portal\Shared;
interface Clock
{
    public function utcNow(): \DateTimeImmutable;
    public function monotonic(): float;
}
