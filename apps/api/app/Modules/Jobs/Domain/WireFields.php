<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class WireFields
{
    /** @param list<string> $required */
    public static function exactObject(array $value, array $required): void
    {
        $keys = array_keys($value);
        sort($keys);
        $expected = $required;
        sort($expected);

        if ($keys !== $expected) {
            throw ContractViolation::invalid('The object has missing or unsupported fields.');
        }
    }

    public static function identifier(mixed $value, string $field, int $maxLength = 128): string
    {
        if (! is_string($value)
            || $value === ''
            || strlen($value) > $maxLength
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/', $value) !== 1) {
            throw ContractViolation::invalid('A required identifier is invalid.');
        }

        return $value;
    }

    public static function nonEmptyString(mixed $value, string $message = 'A required string is invalid.'): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw ContractViolation::invalid($message);
        }

        return $value;
    }

    public static function positiveInteger(mixed $value, string $message = 'A positive integer is required.'): int
    {
        if (! is_int($value) || $value < 1) {
            throw ContractViolation::invalid($message);
        }

        return $value;
    }

    public static function timestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw ContractViolation::invalid('A timestamp with an explicit UTC offset is required.');
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw ContractViolation::invalid('The timestamp is invalid.');
        }
    }

    public static function oneOf(mixed $value, array $allowed, string $message): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw ContractViolation::invalid($message);
        }

        return $value;
    }

    public static function assertJsonValue(mixed $value): void
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                foreach ($value as $key => $item) {
                    if (! is_string($key)) {
                        throw ContractViolation::invalid('The profile snapshot has an invalid object key.');
                    }
                    self::assertJsonValue($item);
                }
                return;
            }

            foreach ($value as $item) {
                self::assertJsonValue($item);
            }
            return;
        }

        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw ContractViolation::invalid('The profile snapshot contains a non-JSON value.');
        }

        if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            throw ContractViolation::invalid('The profile snapshot contains a non-JSON value.');
        }
    }
}
