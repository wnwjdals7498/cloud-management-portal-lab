<?php
declare(strict_types=1);

namespace App\Modules\Simulator\Domain;

use Portal\Shared\PortalException;

final class SimulationCommandValidator
{
    private const FIELDS = [
        'version', 'request_id', 'job_id', 'vm_id', 'operation', 'attempt',
        'external_idempotency_key', 'profile', 'mode',
    ];

    private const PROFILE_FIELDS = ['profile_id', 'profile_version', 'snapshot'];

    private const SNAPSHOT_FIELDS = ['vcpus', 'memory_mb', 'disk_gb', 'network_ref'];

    /** @param array<string, mixed> $command
     *  @return array<string, mixed>
     */
    public function validate(array $command): array
    {
        self::exactFields($command, self::FIELDS);

        if (($command['version'] ?? null) !== 'v1' || ($command['operation'] ?? null) !== 'create' || ($command['mode'] ?? null) !== 'simulated') {
            throw $this->invalid('The simulator only accepts the supported simulated create command.');
        }
        foreach (['request_id', 'job_id', 'vm_id'] as $field) {
            self::identifier($command[$field] ?? null, $field, 64);
        }
        if (! is_int($command['attempt']) || $command['attempt'] < 1) {
            throw $this->invalid('The command attempt is invalid.');
        }
        self::identifier($command['external_idempotency_key'], 'external_idempotency_key', 256);

        $profile = $command['profile'];
        if (! is_array($profile) || array_is_list($profile)) {
            throw $this->invalid('The command profile must be an object.');
        }
        self::exactFields($profile, self::PROFILE_FIELDS);
        self::identifier($profile['profile_id'], 'profile_id', 64);
        self::identifier($profile['profile_version'], 'profile_version', 32);

        $snapshot = $profile['snapshot'];
        if (! is_array($snapshot) || array_is_list($snapshot)) {
            throw $this->invalid('The command profile snapshot must be an object.');
        }
        self::exactFields($snapshot, self::SNAPSHOT_FIELDS);
        foreach (['vcpus', 'memory_mb', 'disk_gb'] as $field) {
            if (! is_int($snapshot[$field]) || $snapshot[$field] < 1) {
                throw $this->invalid('A resource in the profile snapshot is invalid.');
            }
        }
        self::identifier($snapshot['network_ref'], 'network_ref', 128);

        // Rebuild the allowlisted values to avoid persisting extraneous references from caller objects.
        return [
            'version' => 'v1',
            'request_id' => $command['request_id'],
            'job_id' => $command['job_id'],
            'vm_id' => $command['vm_id'],
            'operation' => 'create',
            'attempt' => $command['attempt'],
            'external_idempotency_key' => $command['external_idempotency_key'],
            'profile' => [
                'profile_id' => $profile['profile_id'],
                'profile_version' => $profile['profile_version'],
                'snapshot' => [
                    'vcpus' => $snapshot['vcpus'],
                    'memory_mb' => $snapshot['memory_mb'],
                    'disk_gb' => $snapshot['disk_gb'],
                    'network_ref' => $snapshot['network_ref'],
                ],
            ],
            'mode' => 'simulated',
        ];
    }

    /** @param array<string, mixed> $object
     *  @param list<string> $expected
     */
    private static function exactFields(array $object, array $expected): void
    {
        $keys = array_keys($object);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new PortalException('INVALID_COMMAND', 'The command has missing or unsupported fields.', 400);
        }
    }

    private static function identifier(mixed $value, string $field, int $maximumLength): string
    {
        if (
            ! is_string($value)
            || $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximumLength
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        ) {
            throw new PortalException('INVALID_COMMAND', 'A command identifier is invalid.', 400);
        }

        return $value;
    }

    private function invalid(string $message): PortalException
    {
        return new PortalException('INVALID_COMMAND', $message, 400);
    }
}
