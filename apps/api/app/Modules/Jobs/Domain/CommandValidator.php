<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

use Portal\Shared\CanonicalJson;

final class CommandValidator
{
    private const FIELDS = [
        'version',
        'request_id',
        'job_id',
        'vm_id',
        'operation',
        'attempt',
        'external_idempotency_key',
        'profile',
        'mode',
    ];

    /** @param array<string, mixed> $wire */
    public function validate(array $wire, CommandContext $expected): VmCommand
    {
        WireFields::exactObject($wire, self::FIELDS);

        $version = WireFields::oneOf($wire['version'], ['v1'], 'The command version is unsupported.');
        $operation = WireFields::oneOf($wire['operation'], ['create'], 'The operation is unsupported.');
        $mode = WireFields::oneOf($wire['mode'], ['simulated', 'real'], 'The execution mode is unsupported.');
        $attempt = WireFields::positiveInteger($wire['attempt']);
        $profile = $wire['profile'];

        if (! is_array($profile) || array_is_list($profile)) {
            throw ContractViolation::invalid('The profile must be an object.');
        }

        WireFields::exactObject($profile, ['profile_id', 'profile_version', 'snapshot']);
        $profileId = WireFields::identifier($profile['profile_id'], 'profile_id', 64);
        $profileVersion = WireFields::identifier($profile['profile_version'], 'profile_version', 32);
        $snapshot = $profile['snapshot'];

        if (! is_array($snapshot) || array_is_list($snapshot)) {
            throw ContractViolation::invalid('The profile snapshot must be an object.');
        }
        WireFields::assertJsonValue($snapshot);
        WireFields::exactObject($snapshot, ['vcpus', 'memory_mb', 'disk_gb', 'network_ref']);
        foreach (['vcpus', 'memory_mb', 'disk_gb'] as $resourceField) {
            if (! is_int($snapshot[$resourceField]) || $snapshot[$resourceField] < 1) {
                throw ContractViolation::invalid('The profile snapshot contains an invalid resource value.');
            }
        }
        $networkRef = WireFields::identifier($snapshot['network_ref'], 'network_ref', 128);
        $snapshot = [
            'vcpus' => $snapshot['vcpus'],
            'memory_mb' => $snapshot['memory_mb'],
            'disk_gb' => $snapshot['disk_gb'],
            'network_ref' => $networkRef,
        ];

        $command = new VmCommand(
            $version,
            WireFields::identifier($wire['request_id'], 'request_id', 64),
            WireFields::identifier($wire['job_id'], 'job_id', 64),
            WireFields::identifier($wire['vm_id'], 'vm_id', 64),
            $operation,
            $attempt,
            WireFields::identifier($wire['external_idempotency_key'], 'external_idempotency_key', 128),
            ['profile_id' => $profileId, 'profile_version' => $profileVersion, 'snapshot' => $snapshot],
            $mode,
        );

        if (
            $command->requestId !== $expected->requestId
            || $command->jobId !== $expected->jobId
            || $command->vmId !== $expected->vmId
            || $command->attempt !== $expected->attempt
            || $command->externalIdempotencyKey !== $expected->externalIdempotencyKey
            || ! hash_equals(CanonicalJson::hash($command->profile), CanonicalJson::hash($expected->profile))
            || $command->mode !== $expected->mode
        ) {
            throw ContractViolation::conflict('The command does not match its persisted attempt context.');
        }

        return $command;
    }
}
