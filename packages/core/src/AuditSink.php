<?php
declare(strict_types=1);
namespace Portal\Shared;
interface AuditSink
{
    /** Append within the caller's database transaction; never commits. */
    public function append(array $event): string;
}
