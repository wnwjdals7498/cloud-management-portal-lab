<?php
declare(strict_types=1);

namespace App\Modules\Simulator\Domain;

use Portal\Shared\PortalException;

final class SimulationProfileValidator
{
    private const FIELDS = [
        'profile_version',
        'seed',
        'weights',
        'delay_min_seconds',
        'delay_max_seconds',
        'late_min_seconds',
        'late_max_seconds',
        'duplicate_delay_seconds',
        'maximum_pending',
        'error_codes',
    ];

    private const SCENARIOS = ['success', 'failure', 'late', 'missing', 'duplicate'];

    /** @param array<string, mixed> $configuration */
    public function validate(array $configuration, int $maximumPendingLimit): ValidatedSimulationProfile
    {
        if ($maximumPendingLimit < 1) {
            throw new \InvalidArgumentException('A positive pending limit must be supplied by runtime policy.');
        }

        $keys = array_keys($configuration);
        sort($keys);
        $expected = self::FIELDS;
        sort($expected);

        if ($keys !== $expected) {
            throw $this->invalid('The simulation profile has missing or unsupported fields.');
        }

        $profileVersion = $this->nonEmptyString($configuration['profile_version']);
        $seed = $this->nonEmptyString($configuration['seed']);
        $weights = $configuration['weights'];

        if (! is_array($weights) || array_is_list($weights)) {
            throw $this->invalid('Scenario weights must be an object.');
        }

        $weightKeys = array_keys($weights);
        sort($weightKeys);
        $scenarios = self::SCENARIOS;
        sort($scenarios);

        if ($weightKeys !== $scenarios) {
            throw $this->invalid('Scenario weights must define every supported scenario exactly once.');
        }

        $weightTotal = 0;
        foreach (self::SCENARIOS as $scenario) {
            $weight = $weights[$scenario];
            if (! is_int($weight) || $weight < 0) {
                throw $this->invalid('Scenario weights must be non-negative integers.');
            }
            $weightTotal += $weight;
        }

        if ($weightTotal !== 100) {
            throw $this->invalid('Scenario weights must total one hundred.');
        }

        $delayMin = $this->nonNegativeInteger($configuration['delay_min_seconds']);
        $delayMax = $this->nonNegativeInteger($configuration['delay_max_seconds']);
        $lateMin = $this->nonNegativeInteger($configuration['late_min_seconds']);
        $lateMax = $this->nonNegativeInteger($configuration['late_max_seconds']);
        $duplicateDelay = $this->nonNegativeInteger($configuration['duplicate_delay_seconds']);
        $maximumPending = $this->nonNegativeInteger($configuration['maximum_pending']);

        if ($delayMin > $delayMax || $lateMin > $lateMax || $lateMin <= $delayMax) {
            throw $this->invalid('Simulation delay ranges are inconsistent.');
        }

        if ($maximumPending < 1 || $maximumPending > $maximumPendingLimit) {
            throw $this->invalid('The pending-work limit exceeds the approved runtime limit.');
        }

        $errorCodes = $configuration['error_codes'];
        if (! is_array($errorCodes) || ! array_is_list($errorCodes)) {
            throw $this->invalid('The simulation error allowlist must be a list.');
        }

        foreach ($errorCodes as $errorCode) {
            if (! is_string($errorCode) || preg_match('/^[A-Z][A-Z0-9_]*$/', $errorCode) !== 1) {
                throw $this->invalid('The simulation error allowlist contains an invalid code.');
            }
        }

        if (count(array_unique($errorCodes)) !== count($errorCodes)) {
            throw $this->invalid('The simulation error allowlist contains duplicates.');
        }

        if ($weights['failure'] > 0 && $errorCodes === []) {
            throw $this->invalid('A failure scenario requires at least one allowed error code.');
        }

        return new ValidatedSimulationProfile([
            'profile_version' => $profileVersion,
            'seed' => $seed,
            'weights' => $weights,
            'delay_min_seconds' => $delayMin,
            'delay_max_seconds' => $delayMax,
            'late_min_seconds' => $lateMin,
            'late_max_seconds' => $lateMax,
            'duplicate_delay_seconds' => $duplicateDelay,
            'maximum_pending' => $maximumPending,
            'error_codes' => $errorCodes,
        ]);
    }

    private function nonEmptyString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw $this->invalid('A required profile string is invalid.');
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw $this->invalid('A profile limit must be a non-negative integer.');
        }

        return $value;
    }

    private function invalid(string $message): PortalException
    {
        return new PortalException('INVALID_SIMULATION_PROFILE', $message, 400);
    }
}
