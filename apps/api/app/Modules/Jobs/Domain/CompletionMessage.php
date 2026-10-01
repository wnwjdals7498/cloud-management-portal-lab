<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

use DateTimeImmutable;
use Portal\Shared\CanonicalJson;

final readonly class CompletionMessage
{
    public function __construct(
        public string $eventId,
        public string $jobId,
        public string $externalJobId,
        public string $externalVmId,
        public int $attempt,
        public string $status,
        public DateTimeImmutable $observedAt,
        public ?string $errorCode,
        public string $mode,
    ) {
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'job_id' => $this->jobId,
            'external_job_id' => $this->externalJobId,
            'external_vm_id' => $this->externalVmId,
            'attempt' => $this->attempt,
            'status' => $this->status,
            'observed_at' => $this->observedAt->format('Y-m-d\TH:i:s.u\Z'),
            'error_code' => $this->errorCode,
            'mode' => $this->mode,
        ];
    }

    public function contentHash(): string
    {
        return CanonicalJson::hash($this->canonicalPayload());
    }
}
