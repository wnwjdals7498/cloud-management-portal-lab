<?php
declare(strict_types=1);

namespace Portal\Shared;

final class Identifiers
{
    public static function new(string $prefix): string
    {
        if (preg_match('/\A[a-z][a-z0-9]{0,11}\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Identifier prefix is invalid.');
        }

        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
