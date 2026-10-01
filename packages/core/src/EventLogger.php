<?php
declare(strict_types=1);
namespace Portal\Shared;
interface EventLogger
{
    /** Returns false on degraded logging; must not expose secrets. */
    public function write(string $event, array $context = []): bool;
}
