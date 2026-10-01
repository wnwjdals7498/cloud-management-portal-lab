<?php

declare(strict_types=1);

namespace Portal\Shared;

use RuntimeException;

final class Runtime
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function config(): array
    {
        $path = getenv('PORTAL_CONFIG') ?: self::root() . '/.runtime/local.json';
        if (! is_file($path)) {
            throw new RuntimeException('Local runtime configuration is missing.');
        }
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
}
