<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class CompletionValidator
{
    private const FIELDS = [
        'event_id',
        'job_id',
        'external_job_id',
        'external_vm_id',
        'attempt',
        'status',
        'observed_at',
        'error_code',
        'mode',
    ];

    /** @param array<string, mixed> $wire */
    public function validate(array $wire, ?string $expectedMode = null): CompletionMessage
    {
        WireFields::exactObject($wire, self::FIELDS);

        $status = WireFields::oneOf($wire['status'], ['succeeded', 'failed'], 'The completion status is unsupported.');
        $mode = WireFields::oneOf($wire['mode'], ['simulated', 'real'], 'The completion mode is unsupported.');

        if ($expectedMode !== null && $mode !== $expectedMode) {
            throw ContractViolation::conflict('The completion mode does not match the receiving environment.');
        }
        $errorCode = $wire['error_code'];

        if ($status === 'succeeded' && $errorCode !== null) {
            throw ContractViolation::invalid('Successful completions must not include an error code.');
        }

        if ($status === 'failed' && (! is_string($errorCode) || strlen($errorCode) > 64 || preg_match('/\A[A-Z][A-Z0-9_]*\z/', $errorCode) !== 1)) {
            throw ContractViolation::invalid('Failed completions require a stable error code.');
        }

        return new CompletionMessage(
            WireFields::identifier($wire['event_id'], 'event_id', 64),
            WireFields::identifier($wire['job_id'], 'job_id', 64),
            WireFields::identifier($wire['external_job_id'], 'external_job_id'),
            WireFields::identifier($wire['external_vm_id'], 'external_vm_id'),
            WireFields::positiveInteger($wire['attempt']),
            $status,
            WireFields::timestamp($wire['observed_at']),
            $errorCode,
            $mode,
        );
    }
}
