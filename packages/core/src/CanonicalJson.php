<?php
declare(strict_types=1);

namespace Portal\Shared;

final class CanonicalJson
{
    public static function encode(array $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static function (mixed $item): mixed {
                if (is_array($item)) {
                    return self::normalize($item);
                }

                if (is_object($item) || is_resource($item) || (is_float($item) && ! is_finite($item))) {
                    throw new \InvalidArgumentException('Canonical JSON only accepts finite scalar values and arrays.');
                }

                return $item;
            }, $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Canonical JSON object keys must be strings.');
            }
            if (is_object($item) || is_resource($item) || (is_float($item) && ! is_finite($item))) {
                throw new \InvalidArgumentException('Canonical JSON only accepts finite scalar values and arrays.');
            }
            $normalized[$key] = is_array($item) ? self::normalize($item) : $item;
        }

        ksort($normalized, SORT_STRING);
        return $normalized;
    }
}
