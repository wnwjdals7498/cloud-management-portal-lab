<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final readonly class CompletionContext
{
    public function __construct(
        public string $jobId,
        public ?string $externalJobId,
        public ?string $externalVmId,
        public int $attempt,
        public string $mode,
    ) {
    }
}
