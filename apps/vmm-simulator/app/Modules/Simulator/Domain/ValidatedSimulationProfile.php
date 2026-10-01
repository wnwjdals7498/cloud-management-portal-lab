<?php
declare(strict_types=1);

namespace App\Modules\Simulator\Domain;

final readonly class ValidatedSimulationProfile
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
    }
}
