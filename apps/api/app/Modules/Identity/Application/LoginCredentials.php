<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Portal\Shared\PortalException;

final readonly class LoginCredentials
{
    private function __construct(
        public string $email,
        private string $password,
    ) {
    }

    public static function fromPayload(mixed $payload): self
    {
        if (! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($payload), ['login_identifier', 'credential']) !== []
            || array_diff(['login_identifier', 'credential'], array_keys($payload)) !== []) {
            throw new PortalException('validation_error', 'Login request is invalid.', 400);
        }

        $identifier = $payload['login_identifier'];
        $credential = $payload['credential'];

        if (! is_string($identifier) || ! is_string($credential)) {
            throw new PortalException('validation_error', 'Login request is invalid.', 400);
        }

        $identifier = mb_strtolower(trim($identifier));
        if ($identifier === '' || strlen($identifier) > 254
            || filter_var($identifier, FILTER_VALIDATE_EMAIL) === false
            || $credential === '' || strlen($credential) > 4096) {
            throw new PortalException('validation_error', 'Login request is invalid.', 400);
        }

        return new self($identifier, $credential);
    }

    /** @return array{email: string, password: string} */
    public function toShieldCredentials(): array
    {
        return ['email' => $this->email, 'password' => $this->password];
    }
}
