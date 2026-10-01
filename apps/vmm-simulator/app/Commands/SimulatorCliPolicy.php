<?php
declare(strict_types=1);

namespace App\Commands;

final class SimulatorCliPolicy
{
    public static function allows(string $sapi, string $environment, mixed $mode): bool
    {
        return $sapi === 'cli' && $environment === 'development' && $mode === 'simulated';
    }
}
