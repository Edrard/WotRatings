<?php

declare(strict_types=1);

namespace Edrard\WotRatings\Internal;

use Edrard\WotRatings\Exception\CalculationFailed;
use Edrard\WotRatings\Exception\InvalidInput;

/** @internal */
final class Input
{
    public static function number(mixed $value, string $name, float $minimum = 0, float $maximum = PHP_FLOAT_MAX): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $minimum || $value > $maximum) {
            throw new InvalidInput("$name must be a finite number between $minimum and $maximum.");
        }
        return (float) $value;
    }

    /** @param array<string, mixed> $statistics */
    public static function counter(array $statistics, string $name): float
    {
        return self::number($statistics[$name] ?? null, $name);
    }

    public static function battles(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidInput('battles must be a nonnegative integer.');
        }
        return $value;
    }

    public static function tankId(mixed $value): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw new InvalidInput('Tank IDs must be positive integers.');
        }
        return (int) $value;
    }

    public static function finite(float $value): float
    {
        if (!is_finite($value)) {
            throw new CalculationFailed('Rating calculation overflow.');
        }
        return $value;
    }

    public static function rounded(float $value): int
    {
        $rounded = round(self::finite($value));
        if ($rounded >= PHP_INT_MAX || $rounded < PHP_INT_MIN) {
            throw new CalculationFailed('Rating exceeds integer range.');
        }
        return (int) $rounded;
    }
}
