<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class StatusObservationValidator
{
    private const JOB_STATES = ['accepted', 'running', 'succeeded', 'failed', 'unknown'];
    private const POWER_STATES = ['running', 'stopped', 'starting', 'stopping', 'unknown'];
    private const GUEST_STATES = ['ready', 'not_ready', 'unknown'];

    /** @param array<string, mixed> $wire */
    public function validate(array $wire, ExpectedStatus $expected): StatusObservation
    {
        $allowed = ['external_job_id', 'external_vm_id', 'status', 'observed_at', 'mode', 'power_state', 'guest_readiness', 'error_code'];
        $keys = array_keys($wire);
        sort($keys);
        $required = ['external_job_id', 'status', 'observed_at', 'mode'];
        $expectedKeys = $required;
        sort($expectedKeys);

        if (array_diff($keys, $allowed) !== [] || array_diff($expectedKeys, $keys) !== []) {
            throw ContractViolation::invalid('The status observation has missing or unsupported fields.');
        }

        $mode = WireFields::oneOf($wire['mode'], ['simulated', 'real'], 'The observation mode is unsupported.');
        $status = WireFields::oneOf($wire['status'], self::JOB_STATES, 'The observed job state is unsupported.');
        $power = $wire['power_state'] ?? null;
        $guest = $wire['guest_readiness'] ?? null;
        $error = $wire['error_code'] ?? null;

        if ($mode !== $expected->mode) {
            throw ContractViolation::conflict('The observation mode does not match its request.');
        }

        $externalJobId = WireFields::identifier($wire['external_job_id'], 'external_job_id');
        $externalVmId = isset($wire['external_vm_id'])
            ? WireFields::identifier($wire['external_vm_id'], 'external_vm_id')
            : null;

        if ($externalJobId !== $expected->externalJobId || ($expected->externalVmId !== null && $externalVmId !== $expected->externalVmId)) {
            throw ContractViolation::conflict('The observation target does not match its request.');
        }

        if ($power !== null && (! is_string($power) || ! in_array($power, self::POWER_STATES, true))) {
            throw ContractViolation::invalid('The observed power state is unsupported.');
        }

        if ($guest !== null && (! is_string($guest) || ! in_array($guest, self::GUEST_STATES, true))) {
            throw ContractViolation::invalid('The guest readiness state is unsupported.');
        }

        if ($error !== null && (! is_string($error) || preg_match('/^[A-Z][A-Z0-9_]*$/', $error) !== 1)) {
            throw ContractViolation::invalid('The observation error code is invalid.');
        }

        return new StatusObservation(
            $externalJobId,
            $externalVmId,
            $status,
            WireFields::timestamp($wire['observed_at']),
            $mode,
            $power,
            $guest,
            $error,
        );
    }
}
