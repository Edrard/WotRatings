<?php

declare(strict_types=1);

namespace Edrard\Tests\WotRatings;

use Edrard\WotRatings\LegacyRatings;
use Edrard\WotRatings\WotRatings;
use PHPUnit\Framework\TestCase;

final class LegacyRatingsTest extends TestCase
{
    public function testSyntheticGoldenFromOwnersLegacyCode(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/legacy-golden.json'), true, flags: JSON_THROW_ON_ERROR);
        $current = new WotRatings();
        $legacy = new LegacyRatings();
        foreach ($fixture['cases'] as $case) {
            $stats = $case['statistics'];
            $tier = $case['averageTier'];
            self::assertSame($case['expected']['er'], $current->er($stats, $tier));
            self::assertSame($case['expected']['wn6'], $current->wn6($stats, $tier));
            self::assertSame($case['expected']['wn7'], $legacy->wn7($stats, $tier));
            self::assertSame($case['expected']['broni'], $legacy->broni($stats));
            self::assertSame($case['expected']['noobmeter'], $legacy->noobmeter($stats, $case['tankBattles'], $tier));
        }
        foreach ($fixture['scales'] as $case) {
            foreach ($case['expected'] as $method => $expected) {
                self::assertEqualsWithDelta($expected, $legacy->$method($case['rating']), 0.000000001);
            }
        }
    }

    public function testZeroBattleGuards(): void
    {
        $stats = array_fill_keys(['battles', 'wins', 'damage_dealt', 'frags', 'spotted', 'dropped_capture_points', 'capture_points', 'xp'], 0);
        $legacy = new LegacyRatings();
        self::assertSame(0, $legacy->wn7($stats, 6));
        self::assertSame(0, $legacy->broni($stats));
        self::assertSame(0, $legacy->noobmeter($stats, [], 6));
    }
}
