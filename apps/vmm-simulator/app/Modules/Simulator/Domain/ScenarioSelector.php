<?php
declare(strict_types=1);

namespace App\Modules\Simulator\Domain;

use Portal\Shared\PortalException;

final class ScenarioSelector
{
    private const SCENARIOS = ['success', 'failure', 'late', 'missing', 'duplicate'];

    public function select(string $jobId, ValidatedSimulationProfile $profile, ?string $forcedScenario = null): ScenarioDraw
    {
        $values = $profile->values;
        $digest = hash('sha256', $jobId . $values['seed'] . $values['profile_version']);

        if ($forcedScenario !== null) {
            if (! in_array($forcedScenario, self::SCENARIOS, true)) {
                throw new PortalException('INVALID_SCENARIO', 'The forced simulator scenario is unsupported.', 400);
            }
            return new ScenarioDraw($forcedScenario, $digest, 'development-cli-forced-v1');
        }

        $slot = hexdec(substr($digest, 0, 8)) % 100;
        $cursor = 0;
        foreach (self::SCENARIOS as $scenario) {
            $cursor += $values['weights'][$scenario];
            if ($slot < $cursor) {
                return new ScenarioDraw($scenario, $digest, 'sha256-job-seed-profile-v1');
            }
        }

        throw new \LogicException('Validated scenario weights did not select an outcome.');
    }

    public function rangeValue(string $digest, int $offset, int $minimum, int $maximum): int
    {
        if ($minimum < 0 || $maximum < $minimum) {
            throw new \InvalidArgumentException('A scenario range is invalid.');
        }
        $range = $maximum - $minimum + 1;
        return $minimum + (hexdec(substr($digest, $offset, 8)) % $range);
    }

    public function errorCode(string $digest, array $errorCodes): ?string
    {
        if ($errorCodes === []) {
            return null;
        }
        $index = hexdec(substr($digest, 16, 8)) % count($errorCodes);
        return $errorCodes[$index];
    }
}
