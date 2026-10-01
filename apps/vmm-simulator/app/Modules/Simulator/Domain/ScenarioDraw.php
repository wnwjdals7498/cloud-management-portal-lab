<?php
declare(strict_types=1);

namespace App\Modules\Simulator\Domain;

final readonly class ScenarioDraw
{
    public function __construct(
        public string $scenario,
        public string $digest,
        public string $algorithm,
    ) {
    }
}
