<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final readonly class ExpectedStatus
{
    public function __construct(
        public string $externalJobId,
        public ?string $externalVmId,
        public string $mode,
    ) {
    }
}
