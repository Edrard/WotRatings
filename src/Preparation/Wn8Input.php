<?php

declare(strict_types=1);

namespace Edrard\WotRatings\Preparation;

final class Wn8Input
{
    /**
     * @param array<string, int|float> $statistics
     * @param list<array{tank_id: int, battles: int}> $tankBattles
     * @param list<array{tank_id: int, statistics: array<string, int|float>}> $fullTanks Empty for short inputs.
     * @param array<int, array<string, float>>|null $expectedValues Optional prepared override.
     */
    public function __construct(public array $statistics, public array $tankBattles, public array $fullTanks, public string $source, public string $mode, public ?array $expectedValues = null)
    {
    }
}
