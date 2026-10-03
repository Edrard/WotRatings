<?php

declare(strict_types=1);

namespace Edrard\WotRatings\Preparation;

final class CombatRatingInput
{
    /** @param array<string, int|float> $statistics */
    public function __construct(public array $statistics, public float $averageTier, public string $mode, public string $source = 'short')
    {
    }
}
