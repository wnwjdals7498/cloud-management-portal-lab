<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class CreateRequestValidator
{
    /** @param array<string, mixed> $input */
    public function validate(array $input, string $idempotencyKey): CreateRequest
    {
        WireFields::exactObject($input, ['profile_id']);

        if (trim($idempotencyKey) === '' || trim($idempotencyKey) !== $idempotencyKey || preg_match('/[\x00-\x1f\x7f]/', $idempotencyKey) === 1) {
            throw ContractViolation::invalid('The idempotency key is invalid.');
        }

        return new CreateRequest(
            WireFields::identifier($input['profile_id'], 'profile_id', 64),
            $idempotencyKey,
        );
    }
}
