<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final readonly class CommandContext
{
    /** @param array{profile_id:string,profile_version:string,snapshot:array<string,mixed>} $profile */
    public function __construct(
        public string $requestId,
        public string $jobId,
        public string $vmId,
        public int $attempt,
        public string $externalIdempotencyKey,
        public array $profile,
        public string $mode,
    ) {
    }
}
