<?php

declare(strict_types=1);

namespace Edrard\Tests\WotRatings;

use Edrard\WotRatings\Exception\CalculationFailed;
use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\Exception\PreparationUnavailable;
use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlayerDataPreparerTest extends TestCase
{
    private function stats(int $n = 100): array
    {
        return ['battles' => $n, 'wins' => $n / 2, 'damage_dealt' => $n * 1000, 'frags' => $n, 'spotted' => $n, 'dropped_capture_points' => $n, 'capture_points' => $n];
    }

    private function table(): array
    {
        return [1 => ['expDamage' => 1000, 'expSpot' => 1, 'expFrag' => 1, 'expDef' => 1, 'expWinRate' => 50]];
    }

    private function loaded(array $player, array $short = [], ?array $full = null, array $catalogue = [1 => ['tier' => 6]], ?array $expected = null): PlayerDataPreparer
    {
        $preparer = new PlayerDataPreparer($catalogue, $expected);
        $preparer->setData($player, $short, $full);
        return $preparer;
    }

    private function ratings(): WotRatings
    {
        $ratings = new WotRatings();
        $ratings->setExpectedValues($this->table());
        return $ratings;
    }

    private function wrap(mixed $value, int $id = 123): array
    {
        return ['status' => 'ok', 'data' => [$id => $value]];
    }

    public function testEmptyPreparerReportsNoInventedModes(): void
    {
        $p = new PlayerDataPreparer();
        self::assertSame([], $p->availableModes());
        self::assertSame([], $p->diagnostics('tankstats'));
        self::assertSame([], $p->missingFullTankModes('wn8'));
        self::assertSame([], $p->battleCountMismatches());
        self::assertSame(['battleCountMismatches' => [], 'missingFullTankModes' => [], 'diagnostics' => []], $p->report);
        $this->expectException(InvalidInput::class);
        $p->wn8();
    }

    public function testSetDataChecksBattlesImmediatelyAndMissingModesDoNotChangeStatus(): void
    {
        $p = new PlayerDataPreparer([1 => ['tier' => 6]]);
        self::assertTrue($p->setData(['statistics' => ['all' => $this->stats(), 'random' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]));
        foreach (['er', 'wn6', 'wn8'] as $rating) {
            self::assertSame(['all'], $p->availableModes($rating));
            self::assertSame(['random'], $p->missingFullTankModes($rating));
        }
        self::assertSame([], $p->battleCountMismatches());
        self::assertSame('missing_mode_data', $p->report['diagnostics']['random']['wn8']['issue']);
    }

    public function testStatusSeparatesMismatchedCountsFromMissingCoverage(): void
    {
        $p = new PlayerDataPreparer([1 => ['tier' => 6]]);
        self::assertFalse($p->setData(['statistics' => ['all' => $this->stats(), 'random' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 99]]]));
        self::assertSame(['all' => ['accountBattles' => 100, 'tankBattles' => 99]], $p->battleCountMismatches());
        self::assertSame($p->battleCountMismatches(), $p->report['battleCountMismatches']);
        self::assertSame('battle_count_mismatch', $p->diagnostics()['all']['wn8']['issue']);
        self::assertSame(['random'], $p->missingFullTankModes('wn8'));
        self::assertSame([], $p->availableModes());
    }

    public function testEmptyShortListForPlayedModeIsMissingCoverageRatherThanMismatch(): void
    {
        $p = new PlayerDataPreparer();
        self::assertTrue($p->setData(['statistics' => ['all' => $this->stats()]], []));
        self::assertSame([], $p->battleCountMismatches());
        self::assertSame(['all'], $p->missingFullTankModes('wn8'));
    }

    public function testMismatchesRemainObservableAfterFullFallbackResolvesPreparation(): void
    {
        $p = new PlayerDataPreparer([1 => ['tier' => 6]]);
        self::assertFalse($p->setData(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 99]]], [['tank_id' => 1, 'all' => $this->stats(80)]]));
        self::assertSame(['all'], $p->availableModes('wn8'));
        self::assertSame([], $p->missingFullTankModes('wn8'));
        self::assertSame(99, $p->battleCountMismatches()['all']['tankBattles']);
        self::assertNull($p->diagnostics()['all']['wn8']['issue']);
        self::assertSame(80, $p->wn8()['statistics']['battles']);
        self::assertSame(1565, $this->ratings()->wn8(...$p->wn8()));
    }

    public function testNamedArgumentsWorkInAnyKeyOrderAndContainNoMetadata(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]);
        $ratings = $this->ratings();
        $er = $p->er();
        self::assertSame(['statistics', 'averageTier'], array_keys($er));
        self::assertSame(1177, $ratings->er(...array_reverse($er, true)));
        self::assertSame(1323, $ratings->wn6(...$p->wn6()));
        $wn8 = $p->wn8();
        self::assertSame(['statistics', 'tankBattles', 'expectedValues'], array_keys($wn8));
        self::assertSame(1565, $ratings->wn8(...array_reverse($wn8, true)));
        self::assertNull($wn8['expectedValues']);
    }

    public function testAvailableModesUnionAndPerRatingMetadataRequirements(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats(), 'random' => $this->stats(), 'trees_cut' => 42, 'optional' => null]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], catalogue: []);
        self::assertSame(['all'], $p->availableModes());
        self::assertSame(['all'], $p->availableModes('wn8'));
        self::assertSame([], $p->availableModes('er'));
        self::assertSame('invalid_input', $p->diagnostics()['all']['er']['issue']);
        self::assertSame(['random'], $p->missingFullTankModes('wn8'));
    }

    public function testDifferentRequiredFieldsProduceDifferentMissingModes(): void
    {
        $stats = $this->stats();
        unset($stats['capture_points']);
        $p = $this->loaded(['statistics' => ['random' => $stats]], full: [['tank_id' => 1, 'random' => $stats]]);
        self::assertSame([], $p->availableModes('er'));
        self::assertSame(['random'], $p->missingFullTankModes('er'));
        self::assertSame([], $p->missingFullTankModes('wn8'));
        self::assertSame(['random'], $p->availableModes('wn6'));
        self::assertSame(1565, $this->ratings()->wn8(...$p->wn8('random')));
    }

    public function testShortFirstAndExplicitFullUseTheirOwnCountersAndTier(): void
    {
        $full = $this->stats(80);
        $full['damage_dealt'] *= 2;
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], [['tank_id' => 2, 'all' => $full]], [1 => ['tier' => 4], 2 => ['tier' => 8]]);
        foreach (['er', 'wn6'] as $rating) {
            self::assertSame(100, $p->$rating()['statistics']['battles']);
            self::assertSame(4.0, $p->$rating()['averageTier']);
            self::assertSame(80, $p->$rating(source: 'tankstats')['statistics']['battles']);
            self::assertSame(8.0, $p->$rating(source: 'tankstats')['averageTier']);
            self::assertSame(160000.0, $p->$rating(source: 'tankstats')['statistics']['damage_dealt']);
        }
        self::assertSame('short', $p->diagnostics()['all']['wn8']['source']);
        self::assertSame('full', $p->diagnostics('tankstats')['all']['wn8']['source']);
        self::assertSame(80, $p->wn8FromFull()['tanks'][0]['statistics']['battles']);
    }

    public function testFlatAllCountsNeverBecomeRandomWeights(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats(), 'random' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], [['tank_id' => 1, 'random' => $this->stats(60)]]);
        self::assertSame(60, $p->er('random')['statistics']['battles']);
        self::assertSame(60, $p->wn6('random')['statistics']['battles']);
        self::assertSame(60, $p->wn8('random')['tankBattles'][0]['battles']);
        self::assertSame('short', $p->diagnostics()['all']['er']['source']);
    }

    public function testMissingAllInModeMapFallsBackAndModeKeyedCountsAreComparable(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats(), 'random' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['random' => ['battles' => 100]]]], [['tank_id' => 1, 'all' => $this->stats(80)]]);
        self::assertSame([], $p->battleCountMismatches());
        foreach (['er', 'wn6', 'wn8'] as $rating) {
            self::assertSame(['all', 'random'], $p->availableModes($rating));
            self::assertSame('full', $p->diagnostics()['all'][$rating]['source']);
            self::assertSame('short', $p->diagnostics()['random'][$rating]['source']);
        }
        self::assertSame(80, $p->wn8()['statistics']['battles']);
    }

    public function testFullModesCanExistWithoutAccountMode(): void
    {
        $p = $this->loaded(['statistics' => []], full: [['tank_id' => 1, 'random' => $this->stats(80)]]);
        self::assertSame(['random'], $p->availableModes('er'));
        self::assertSame(80, $p->wn6('random')['statistics']['battles']);
        self::assertSame(1565, $this->ratings()->wn8(...$p->wn8('random')));
    }

    public function testFullWeightedTierUsesFullBattlesWithoutAccountMixing(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], full: [['tank_id' => 1, 'all' => $this->stats(20)], ['tank_id' => 2, 'all' => $this->stats(60)]], catalogue: [1 => ['tier' => 4], 2 => ['level' => 8]]);
        self::assertSame(7.0, $p->er()['averageTier']);
        self::assertSame(80, $p->wn6()['statistics']['battles']);
    }

    public function testLateFullUpdatesReportAndCachedFailures(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats(), 'random' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 99]]]);
        $oldReport = $p->report;
        self::assertSame([], $p->availableModes(source: 'tankstats'));
        try {
            $p->wn8('random');
            self::fail('Missing random must fail.');
        } catch (PreparationUnavailable) {
            $p->setFullTankStatistics([['tank_id' => 1, 'all' => $this->stats(80), 'random' => $this->stats(60)]]);
        }
        self::assertSame(['all', 'random'], $p->availableModes('wn8', 'tankstats'));
        self::assertSame([], $p->report['missingFullTankModes']['wn8']);
        self::assertSame(['random'], $oldReport['missingFullTankModes']['wn8']);
        self::assertSame(60, $p->wn8('random')['statistics']['battles']);
        self::assertSame(99, $p->battleCountMismatches()['all']['tankBattles']);
    }

    public function testReplacingFullClearsAllCachedModesCountersAndTier(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], full: [['tank_id' => 1, 'all' => $this->stats(80)]], catalogue: [1 => ['tier' => 4], 2 => ['tier' => 8]]);
        $old = $p->wn8FromFull();
        self::assertSame(4.0, $p->er()['averageTier']);
        self::assertSame(['all'], $p->availableModes('wn8', 'tankstats'));
        $p->setFullTankStatistics([['tank_id' => 2, 'random' => $this->stats(40)]]);
        self::assertSame(['random'], $p->availableModes('wn8', 'tankstats'));
        self::assertSame(8.0, $p->wn6('random')['averageTier']);
        self::assertSame(80, $old['tanks'][0]['statistics']['battles']);
        self::assertSame(['all'], $p->missingFullTankModes('wn8'));
    }

    public function testSetDataReusesConfigurationAndClearsPreviousPlayerFullIdentityAndCaches(): void
    {
        $p = new PlayerDataPreparer([1 => ['tier' => 6]], $this->table());
        self::assertFalse($p->setData(['account_id' => 123, 'statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 99]]], [['account_id' => 123, 'tank_id' => 1, 'all' => $this->stats(80), 'random' => $this->stats(60)]]));
        $old = $p->wn8FromFull('random');
        self::assertSame(['all', 'random'], $p->availableModes('wn8', 'tankstats'));
        self::assertTrue($p->setData(['account_id' => 456, 'statistics' => ['all' => $this->stats(40)]], [['account_id' => 456, 'tank_id' => 1, 'statistics' => ['battles' => 40]]]));
        self::assertSame([], $p->availableModes('wn8', 'tankstats'));
        self::assertSame([], $p->battleCountMismatches());
        self::assertSame(['all'], $p->availableModes('er'));
        self::assertSame(40, $p->wn8()['statistics']['battles']);
        self::assertSame(6.0, $p->er()['averageTier']);
        self::assertSame(1565, (new WotRatings())->wn8(...$p->wn8()));
        self::assertSame(60, $old['tanks'][0]['statistics']['battles']);
        $p->setFullTankStatsResponse($this->wrap([['tank_id' => 1, 'all' => $this->stats(20)]], 456));
        self::assertSame(20, $p->wn8FromFull()['tanks'][0]['statistics']['battles']);
    }

    public function testEditableArraysAreIndependentOfCacheSourceAndOtherResults(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], [['tank_id' => 1, 'all' => $this->stats()]], expected: $this->table());
        $ratings = $this->ratings();
        $er = $p->er();
        $er['statistics']['damage_dealt'] *= 2;
        $er['averageTier'] = 8;
        self::assertNotSame(1177, $ratings->er(...$er));
        self::assertSame(1177, $ratings->er(...$p->er()));
        $wn8 = $p->wn8();
        $wn8['statistics']['damage_dealt'] *= 2;
        self::assertSame(3091, $ratings->wn8(...$wn8));
        $wn8['expectedValues'][1]['expDamage'] *= 2;
        self::assertSame(1565, $ratings->wn8(...$wn8));
        $wn8['tankBattles'][0]['battles'] = 999;
        $full = $p->wn8FromFull();
        $full['tanks'][0]['statistics']['damage_dealt'] *= 2;
        self::assertSame(3091, $ratings->wn8FromFull(...$full));
        self::assertSame(1565, $ratings->wn8(...$p->wn8()));
        self::assertSame(1565, $ratings->wn8FromFull(...$p->wn8FromFull()));
        $report = $p->report;
        $report['diagnostics']['all']['wn8']['ready'] = false;
        self::assertTrue($p->report['diagnostics']['all']['wn8']['ready']);
        self::assertSame($p->diagnostics(), $p->report['diagnostics']);
    }

    public function testReportCannotBeOverwrittenExternally(): void
    {
        $p = new PlayerDataPreparer();
        $this->expectException(\Error::class);
        $p->report = [];
    }

    public function testSetApiResponsesAndFactoryRetainIdentityWhenFieldsOmitIt(): void
    {
        $info = $this->wrap(['statistics' => ['all' => $this->stats()]]);
        $short = $this->wrap([['tank_id' => 1, 'statistics' => ['battles' => 99]]]);
        $p = new PlayerDataPreparer(['status' => 'ok', 'data' => [1 => ['tier' => 6]]]);
        self::assertFalse($p->setApiResponses(123, $info, $short));
        $factory = PlayerDataPreparer::fromApiResponses(123, $info, $short, catalogue: [1 => ['tier' => 6]]);
        self::assertSame($p->report, $factory->report);
        $p->setFullTankStatsResponse($this->wrap([['account_id' => 123, 'tank_id' => 1, 'all' => $this->stats(80)]]));
        self::assertSame(80, $p->wn8()['statistics']['battles']);
    }

    public function testInvalidInitialReplacementDoesNotDestroyPreviousSnapshot(): void
    {
        $p = $this->loaded(['account_id' => 123, 'statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]);
        $oldReport = $p->report;
        foreach ([[['statistics' => null], []], [['account_id' => 456, 'statistics' => []], [['tank_id' => 1, 'account_id' => 123]]], [['statistics' => []], [['tank_id' => 1], ['tank_id' => 1]]]] as [$player, $short]) {
            try {
                $p->setData($player, $short);
                self::fail('Invalid replacement must fail.');
            } catch (InvalidInput) {
                self::assertSame($oldReport, $p->report);
                self::assertSame(100, $p->wn8()['statistics']['battles']);
            }
        }
    }

    #[DataProvider('badEnvelopes')]
    public function testInvalidEnvelopesDoNotReplaceCurrentPlayer(array $info, array $short, ?array $full): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]);
        $before = $p->report;
        try {
            $p->setApiResponses(123, $info, $short, $full);
            self::fail('Invalid envelope must fail.');
        } catch (InvalidInput) {
            self::assertSame($before, $p->report);
        }
    }

    public static function badEnvelopes(): array
    {
        $info = ['status' => 'ok', 'data' => [123 => ['statistics' => []]]];
        $short = ['status' => 'ok', 'data' => [123 => []]];
        return [
            'error' => [['status' => 'error'], $short, null],
            'wrong id' => [['status' => 'ok', 'data' => [124 => []]], $short, null],
            'absent player' => [['status' => 'ok', 'data' => [123 => null]], $short, null],
            'identity' => [['status' => 'ok', 'data' => [123 => ['account_id' => 124, 'statistics' => []]]], $short, null],
            'null full record' => [$info, $short, ['status' => 'ok', 'data' => [123 => null]]],
        ];
    }

    public function testInvalidFullReplacementRetainsSnapshotAndForcedReadiness(): void
    {
        $p = $this->loaded(['account_id' => 123, 'statistics' => ['all' => $this->stats()]], full: [['tank_id' => 1, 'all' => $this->stats(80)]]);
        self::assertSame(['all'], $p->availableModes('wn8', 'tankstats'));
        foreach ([[false], [['tank_id' => 0]], [['tank_id' => 1], ['tank_id' => 1]], [['tank_id' => 1, 'account_id' => 124]]] as $invalid) {
            try {
                $p->setFullTankStatistics($invalid);
                self::fail('Invalid full replacement must fail.');
            } catch (InvalidInput) {
                self::assertSame(80, $p->wn8FromFull()['tanks'][0]['statistics']['battles']);
                self::assertSame(['all'], $p->availableModes('wn8', 'tankstats'));
            }
        }
    }

    public function testFullEnvelopeRequiresKnownIdentity(): void
    {
        $p = $this->loaded(['statistics' => []]);
        $this->expectException(InvalidInput::class);
        $p->setFullTankStatsResponse($this->wrap([]));
    }

    public function testNoPartialSummingWhenOneFullTankOmitsMode(): void
    {
        $p = $this->loaded(['statistics' => ['random' => $this->stats()]], full: [['tank_id' => 1, 'random' => $this->stats(50)], ['tank_id' => 2, 'all' => $this->stats(50)]], catalogue: [1 => ['tier' => 6], 2 => ['tier' => 8]]);
        self::assertSame([], $p->availableModes('wn8'));
        self::assertSame(['random', 'all'], $p->missingFullTankModes('wn8'));
        $this->expectException(PreparationUnavailable::class);
        $p->wn8('random');
    }

    public function testMissingAccountCountersCanFallBackToCompleteFull(): void
    {
        $p = $this->loaded(['statistics' => ['all' => ['battles' => 100]]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], [['tank_id' => 1, 'all' => $this->stats(80)]]);
        self::assertSame(['all'], $p->availableModes());
        self::assertSame(80, $p->er()['statistics']['battles']);
        self::assertSame('full', $p->diagnostics()['all']['wn8']['source']);
    }

    public function testMalformedCountersAreNotMissingDataOrNumericMismatches(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['wins' => 50]]], [['tank_id' => 1, 'all' => $this->stats(80)]]);
        self::assertSame([], $p->battleCountMismatches());
        self::assertSame([], $p->missingFullTankModes('wn8'));
        self::assertSame('invalid_input', $p->diagnostics()['all']['wn8']['issue']);
        $this->expectException(InvalidInput::class);
        $p->wn8();
    }

    public function testExplicitFullSourceIgnoresBadAccountCounters(): void
    {
        $p = $this->loaded(['statistics' => ['all' => ['battles' => 'broken']]], full: [['tank_id' => 1, 'all' => $this->stats(80)]]);
        self::assertSame([], $p->availableModes());
        self::assertSame(['all'], $p->availableModes(source: 'tankstats'));
        self::assertSame(80, $p->er(source: 'tankstats')['statistics']['battles']);
        self::assertSame(1565, $this->ratings()->wn8FromFull(...$p->wn8FromFull()));
    }

    public function testExplicitFullDoesNotSilentlyUseShortData(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]);
        self::assertSame(['all'], $p->availableModes('wn8'));
        self::assertSame([], $p->availableModes('wn8', 'tankstats'));
        $this->expectException(PreparationUnavailable::class);
        $p->wn8FromFull();
    }

    public function testZeroBattlesRemainCalculableWithExpectedAndRequiredCounters(): void
    {
        $p = $this->loaded(['statistics' => ['all' => $this->stats(0)]], expected: $this->table());
        self::assertSame(0, $this->ratings()->er(...$p->er()));
        self::assertSame(0, $this->ratings()->wn6(...$p->wn6()));
        self::assertSame(0, $this->ratings()->wn8(...$p->wn8()));
    }

    public function testExplicitZeroFullCanBeReadyWhileAutoRejectsReplacingPlayedMode(): void
    {
        $p = $this->loaded(['statistics' => ['random' => $this->stats()]], full: [['tank_id' => 1, 'random' => $this->stats(0)]]);
        self::assertSame([], $p->availableModes('wn8'));
        self::assertSame(['random'], $p->missingFullTankModes('wn8'));
        self::assertSame(['random'], $p->availableModes('wn8', 'tankstats'));
        self::assertSame(0, $this->ratings()->wn8FromFull(...$p->wn8FromFull('random')));
    }

    public function testUnknownSelectorsAreRejected(): void
    {
        $p = $this->loaded(['statistics' => []]);
        foreach ([fn () => $p->availableModes('wn7'), fn () => $p->missingFullTankModes('wn7'), fn () => $p->diagnostics('full'), fn () => $p->wn8(source: 'short'), fn () => $p->er('unknown')] as $call) {
            try {
                $call();
                self::fail('Unknown selector must fail.');
            } catch (InvalidInput $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testFullCounterOverflowIsDiagnosedSeparately(): void
    {
        $stats = $this->stats(1);
        $stats['damage_dealt'] = PHP_FLOAT_MAX * 0.75;
        $p = $this->loaded(['statistics' => ['all' => $this->stats(2)]], full: [['tank_id' => 1, 'all' => $stats], ['tank_id' => 2, 'all' => $stats]]);
        self::assertSame('calculation_failed', $p->diagnostics()['all']['wn8']['issue']);
        $this->expectException(CalculationFailed::class);
        $p->wn8();
    }

    public function testSetDataStatusIsNotASubstituteForCheckingMalformedCounters(): void
    {
        $p = new PlayerDataPreparer();
        self::assertTrue($p->setData(['statistics' => ['all' => ['battles' => 'broken']]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]));
        self::assertSame([], $p->battleCountMismatches());
        self::assertSame('invalid_input', $p->report['diagnostics']['all']['wn8']['issue']);
    }
}
