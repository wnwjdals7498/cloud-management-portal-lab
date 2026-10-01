<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

use DateTimeImmutable;

final readonly class StatusObservation
{
    public function __construct(
        public string $externalJobId,
        public ?string $externalVmId,
        public string $status,
        public DateTimeImmutable $observedAt,
        public string $mode,
        public ?string $powerState = null,
        public ?string $guestReadiness = null,
        public ?string $errorCode = null,
    ) {
    }
}
