<?php

declare(strict_types=1);

namespace Edrard\Tests\WotRatings;

use Edrard\WotRatings\Exception\CalculationFailed;
use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\Exception\MissingExpectedValues;
use Edrard\WotRatings\WotRatings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WotRatingsTest extends TestCase
{
    private function statistics(): array
    {
        return ['battles' => 100, 'wins' => 50, 'damage_dealt' => 100000, 'frags' => 100, 'spotted' => 100, 'dropped_capture_points' => 100, 'capture_points' => 100];
    }

    private function table(): array
    {
        return [1 => ['expDamage' => 1000, 'expFrag' => 1, 'expSpot' => 1, 'expDef' => 1, 'expWinRate' => 50]];
    }

    public function testExpectedRatiosOfOneProduceKnownScore(): void
    {
        $ratings = new WotRatings();
        $ratings->setExpectedValues($this->table());
        self::assertSame(1565, $ratings->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]]));
    }

    public function testMissingInstalledTableIsExplicit(): void
    {
        $this->expectException(MissingExpectedValues::class);
        (new WotRatings())->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]]);
    }

    public function testFullCountersAndPerCallOverride(): void
    {
        $ratings = new WotRatings();
        $full = [['tank_id' => 1, 'statistics' => $this->statistics()]];
        self::assertSame(1565, $ratings->wn8FromFull($full, $this->table()));
        $ratings->setExpectedValues($this->table());
        $override = $this->table();
        $override[1]['expDamage'] = 2000;
        self::assertNotSame(1565, $ratings->wn8FromFull($full, $override));
        self::assertSame(1565, $ratings->wn8FromFull($full));
    }

    public function testFullCountersRequireExpectedValues(): void
    {
        $this->expectException(MissingExpectedValues::class);
        (new WotRatings())->wn8FromFull([['tank_id' => 1, 'statistics' => $this->statistics()]]);
    }

    public function testOverrideWorksWithoutDefaultAndDoesNotInstallIt(): void
    {
        $ratings = new WotRatings();
        self::assertSame(1565, $ratings->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]], $this->table()));
        $this->expectException(MissingExpectedValues::class);
        $ratings->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]]);
    }

    public function testOverrideDoesNotMutateDefaultOrAnotherInstance(): void
    {
        $a = new WotRatings();
        $b = new WotRatings();
        $a->setExpectedValues($this->table());
        $b->setExpectedValues($this->table());
        $override = $this->table();
        $override[1]['expDamage'] = 2000;
        $tanks = [['tank_id' => 1, 'battles' => 100]];
        self::assertNotSame(1565, $a->wn8($this->statistics(), $tanks, $override));
        self::assertSame(1565, $a->wn8($this->statistics(), $tanks));
        self::assertSame(1565, $b->wn8($this->statistics(), $tanks));
    }

    public function testInvalidReplacementIsAtomicAndEmptyOverrideDoesNotFallBack(): void
    {
        $ratings = new WotRatings();
        $ratings->setExpectedValues($this->table());
        try {
            $ratings->setExpectedValues([]);
            self::fail('Empty replacement must fail.');
        } catch (InvalidInput) {
            self::assertSame(1565, $ratings->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]]));
        }
        $this->expectException(InvalidInput::class);
        $ratings->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]], []);
    }

    public function testXvmRowsAndTankMapHaveSameMeaning(): void
    {
        $row = $this->table()[1];
        $row['IDNum'] = 1;
        self::assertSame(1565, (new WotRatings())->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]], [$row]));
    }

    public function testWeightedExpectedValuesForMixedVehicles(): void
    {
        $table = $this->table();
        $table[2] = $table[1];
        $table[2]['expDamage'] = 2000;
        $stats = $this->statistics();
        $stats['damage_dealt'] = 150000;
        self::assertSame(1565, (new WotRatings())->wn8($stats, [['tank_id' => 1, 'battles' => 50], ['tank_id' => 2, 'battles' => 50]], $table));
    }

    public function testZeroBattlesAndNoCollectionThreshold(): void
    {
        $ratings = new WotRatings();
        $ratings->setExpectedValues($this->table());
        $zero = array_fill_keys(array_keys($this->statistics()), 0);
        self::assertSame(0, $ratings->er($zero, 6));
        self::assertSame(0, $ratings->wn6($zero, 6));
        self::assertSame(0, $ratings->wn8($zero, []));
        self::assertSame(1565, $ratings->wn8($this->statistics(), [['tank_id' => 1, 'battles' => 100]]));
        self::assertGreaterThan(0, $ratings->er($this->statistics(), 6));
        self::assertGreaterThan(0, $ratings->wn6($this->statistics(), 6));
    }

    public function testWn8LowerClamp(): void
    {
        $stats = $this->statistics();
        foreach (['wins', 'damage_dealt', 'frags', 'spotted', 'dropped_capture_points'] as $field) {
            $stats[$field] = 0;
        }
        self::assertSame(0, (new WotRatings())->wn8($stats, [['tank_id' => 1, 'battles' => 100]], $this->table()));
    }

    public function testMissingTankIsExplicit(): void
    {
        $this->expectException(MissingExpectedValues::class);
        (new WotRatings())->wn8($this->statistics(), [['tank_id' => 2, 'battles' => 100]], $this->table());
    }

    #[DataProvider('invalidTables')]
    public function testInvalidTablesAreRejected(array $table): void
    {
        $this->expectException(InvalidInput::class);
        (new WotRatings())->setExpectedValues($table);
    }

    public static function invalidTables(): array
    {
        $row = ['expDamage' => 1000, 'expFrag' => 1, 'expSpot' => 1, 'expDef' => 1, 'expWinRate' => 50];
        return [
            'empty' => [[]],
            'zero' => [[1 => array_replace($row, ['expDamage' => 0])]],
            'nan' => [[1 => array_replace($row, ['expDamage' => NAN])]],
            'string' => [[1 => array_replace($row, ['expDamage' => '1000'])]],
            'missing' => [[1 => ['expDamage' => 1000]]],
            'invalid ID' => [['01' => $row]],
            'conflicting ID' => [[1 => $row + ['IDNum' => 2]]],
            'duplicate ID' => [[$row + ['IDNum' => 1], $row + ['IDNum' => 1]]],
            'win units' => [[1 => array_replace($row, ['expWinRate' => 101])]],
        ];
    }

    #[DataProvider('invalidTanks')]
    public function testInvalidTankTotalsAreRejected(array $tanks): void
    {
        $this->expectException(InvalidInput::class);
        (new WotRatings())->wn8($this->statistics(), $tanks, $this->table());
    }

    public static function invalidTanks(): array
    {
        return [
            'missing battles' => [[['tank_id' => 1]]],
            'negative' => [[['tank_id' => 1, 'battles' => -1]]],
            'mismatched' => [[['tank_id' => 1, 'battles' => 99]]],
            'duplicate' => [[['tank_id' => 1, 'battles' => 50], ['tank_id' => 1, 'battles' => 50]]],
            'too many' => [[['tank_id' => 1, 'battles' => 101]]],
        ];
    }

    public function testImpossibleWinsRejected(): void
    {
        $stats = $this->statistics();
        $stats['wins'] = 101;
        $this->expectException(InvalidInput::class);
        (new WotRatings())->wn6($stats, 6);
    }

    public function testNonfiniteInputsRejected(): void
    {
        $stats = $this->statistics();
        $stats['damage_dealt'] = INF;
        $this->expectException(InvalidInput::class);
        (new WotRatings())->er($stats, 6);
    }

    public function testRatingOverflowRejected(): void
    {
        $stats = $this->statistics();
        $stats['frags'] = PHP_FLOAT_MAX;
        $this->expectException(CalculationFailed::class);
        (new WotRatings())->er($stats, 6);
    }
}
