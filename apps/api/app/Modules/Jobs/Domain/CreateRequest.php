<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

use Portal\Shared\CanonicalJson;

final readonly class CreateRequest
{
    public function __construct(
        public string $profileId,
        public string $idempotencyKey,
    ) {
    }

    /** @return array{profile_id:string} */
    public function canonicalPayload(): array
    {
        return ['profile_id' => $this->profileId];
    }

    public function bodyFingerprint(): string
    {
        return CanonicalJson::hash($this->canonicalPayload());
    }
}
