<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class JobAcceptanceValidator
{
    /** @param array<string, mixed> $wire */
    public function validate(array $wire): JobAcceptance
    {
        WireFields::exactObject($wire, ['job_id', 'vm_id', 'status', 'mode', 'request_id']);

        $status = WireFields::oneOf($wire['status'], ['queued'], 'The acceptance status is unsupported.');
        $mode = WireFields::oneOf($wire['mode'], ['simulated', 'real'], 'The acceptance mode is unsupported.');

        return new JobAcceptance(
            WireFields::identifier($wire['job_id'], 'job_id'),
            WireFields::identifier($wire['vm_id'], 'vm_id'),
            $status,
            $mode,
            WireFields::identifier($wire['request_id'], 'request_id'),
        );
    }
}
