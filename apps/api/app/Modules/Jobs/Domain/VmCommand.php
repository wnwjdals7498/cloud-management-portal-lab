<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final readonly class VmCommand
{
    /** @param array{profile_id:string,profile_version:string,snapshot:array<string,mixed>} $profile */
    public function __construct(
        public string $version,
        public string $requestId,
        public string $jobId,
        public string $vmId,
        public string $operation,
        public int $attempt,
        public string $externalIdempotencyKey,
        public array $profile,
        public string $mode,
    ) {
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'version' => $this->version,
            'request_id' => $this->requestId,
            'job_id' => $this->jobId,
            'vm_id' => $this->vmId,
            'operation' => $this->operation,
            'attempt' => $this->attempt,
            'external_idempotency_key' => $this->externalIdempotencyKey,
            'profile' => $this->profile,
            'mode' => $this->mode,
        ];
    }
}
