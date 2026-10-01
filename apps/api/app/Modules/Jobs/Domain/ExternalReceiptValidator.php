<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class ExternalReceiptValidator
{
    /** @param array<string, mixed> $wire */
    public function validate(array $wire, string $expectedMode): ExternalReceipt
    {
        WireFields::exactObject($wire, ['external_job_id', 'external_vm_id', 'status', 'observed_at', 'mode']);

        $status = WireFields::oneOf($wire['status'], ['accepted', 'running'], 'The receipt status is unsupported.');
        $mode = WireFields::oneOf($wire['mode'], ['simulated', 'real'], 'The receipt mode is unsupported.');

        if ($mode !== $expectedMode) {
            throw ContractViolation::conflict('The receipt mode does not match the active execution mode.');
        }

        return new ExternalReceipt(
            WireFields::identifier($wire['external_job_id'], 'external_job_id'),
            WireFields::identifier($wire['external_vm_id'], 'external_vm_id'),
            $status,
            WireFields::timestamp($wire['observed_at']),
            $mode,
        );
    }
}
