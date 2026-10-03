<?php

declare(strict_types=1);

namespace Edrard\Tests\WotRatings;

use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;
use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\Exception\PreparationUnavailable;
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

    public function testShortMatchingDataAndCombatInputs(): void
    {
        $stats = $this->stats();
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $stats]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], catalogue: [1 => ['tier' => 6]]);
        $input = $preparer->wn8()->input;
        self::assertNotNull($input);
        self::assertSame('short', $input->source);
        self::assertSame('all', $input->mode);
        $ratings = new WotRatings();
        self::assertSame(1565, $ratings->wn8($input->statistics, $input->tankBattles, $this->table()));
        $er = $preparer->er();
        $wn6 = $preparer->wn6();
        self::assertSame(6.0, $er->averageTier);
        self::assertSame($ratings->er($stats, 6), $ratings->er($er->statistics, $er->averageTier));
        self::assertSame($ratings->wn6($stats, 6), $ratings->wn6($wn6->statistics, $wn6->averageTier));
    }

    public function testMismatchWithoutFullDataReturnsReason(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 99]]]);
        $result = $preparer->wn8();
        self::assertNull($result->input);
        self::assertNotNull($result->reason);
    }

    public function testMismatchUsesFullCountersWithoutAccountTotals(): void
    {
        $full = [['tank_id' => 1, 'all' => $this->stats(80)]];
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 99]]], $full);
        $input = $preparer->wn8()->input;
        self::assertNotNull($input);
        self::assertSame('full', $input->source);
        self::assertSame(80, $input->statistics['battles']);
        $ratings = new WotRatings();
        self::assertSame(1565, $ratings->wn8FromFull($input->fullTanks, $this->table()));
        self::assertSame(1565, $ratings->wn8($input->statistics, $input->tankBattles, $this->table()));
    }

    public function testSelectedModeNeverUsesFlatAllShortStatistics(): void
    {
        $stats = $this->stats();
        $full = [['tank_id' => 1, 'all' => $this->stats(90), 'random' => $stats, 'team' => $this->stats(20)]];
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $stats, 'random' => $stats, 'team' => $this->stats(20)]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], $full, [1 => ['level' => 8]]);
        $random = $preparer->wn8('random')->input;
        self::assertNotNull($random);
        self::assertSame('full', $random->source);
        self::assertSame('random', $random->mode);
        self::assertSame(100, $random->statistics['battles']);
        self::assertSame(20, $preparer->wn8('team')->input?->statistics['battles']);
        self::assertSame(8.0, $preparer->er('random')->averageTier);
        self::assertSame(8.0, $preparer->wn6('team')->averageTier);
        $without = new PlayerDataPreparer(['statistics' => ['random' => $stats]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]);
        self::assertNull($without->wn8('random')->input);
    }

    public function testModeAwareShortRowsAreSupported(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['random' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['random' => ['battles' => 100]]]]);
        self::assertSame('short', $preparer->wn8('random')->input?->source);
    }

    public function testMissingDetailedModeOrEmptyDataIsUnavailable(): void
    {
        foreach ([[], [['tank_id' => 1, 'all' => $this->stats()]], [['tank_id' => 1, 'random' => $this->stats(0)]]] as $full) {
            $preparer = new PlayerDataPreparer(['statistics' => ['random' => $this->stats()]], fullTanks: $full);
            self::assertNull($preparer->wn8('random')->input);
            self::assertNotNull($preparer->wn8('random')->reason);
        }
    }

    public function testMissingPlayerModeIsInvalid(): void
    {
        $this->expectException(InvalidInput::class);
        (new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]]))->wn8('random');
    }

    public function testPlayedAccountWithoutTankCountsCannotPrepareTier(): void
    {
        $this->expectException(PreparationUnavailable::class);
        (new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]]))->er();
    }

    public function testWeightedTierAndFullOnlyPreparation(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], fullTanks: [['tank_id' => 1, 'all' => $this->stats(25)], ['tank_id' => 2, 'all' => $this->stats(75)]], catalogue: [1 => ['tier' => 2], 2 => ['tier' => 6]]);
        self::assertSame(5.0, $preparer->er()->averageTier);
        self::assertSame('full', $preparer->wn8()->input?->source);
    }

    public function testDecodedApiEnvelopesAndExpectedDocument(): void
    {
        $wrap = static fn (mixed $record): array => ['status' => 'ok', 'data' => [123 => $record]];
        $preparer = PlayerDataPreparer::fromApiResponses(
            123,
            $wrap(['account_id' => 123, 'statistics' => ['all' => $this->stats()]]),
            $wrap([['tank_id' => 1, 'statistics' => ['battles' => 100]]]),
            $wrap([['tank_id' => 1, 'all' => $this->stats(80)]]),
            expectedValues: ['data' => $this->table()]
        );
        $short = $preparer->wn8()->input;
        $full = $preparer->wn8FromFull()->input;
        self::assertNotNull($short);
        self::assertNotNull($full);
        self::assertSame('short', $short->source);
        self::assertSame('full', $full->source);
        self::assertSame(80, $full->statistics['battles']);
        self::assertSame(1565, (new WotRatings())->wn8($short->statistics, $short->tankBattles, $short->expectedValues));
        self::assertSame(1565, (new WotRatings())->wn8FromFull($full->fullTanks, $full->expectedValues));
    }

    public function testErrorEnvelopeIsRejected(): void
    {
        $this->expectException(InvalidInput::class);
        PlayerDataPreparer::fromApiResponses(123, ['status' => 'error'], ['status' => 'ok', 'data' => [123 => []]]);
    }

    public function testPreparedDataCanBeEditedBeforeCalculationWithoutMutatingSource(): void
    {
        $stats = $this->stats();
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $stats]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]], [['tank_id' => 1, 'all' => $stats]], [1 => ['tier' => 6]], $this->table());
        $ratings = new WotRatings();
        $ratings->setExpectedValues($this->table());
        $er = $preparer->er();
        $baseline = $ratings->er($er->statistics, $er->averageTier);
        $er->statistics['damage_dealt'] *= 2;
        $er->averageTier = 8;
        self::assertNotSame($baseline, $ratings->er($er->statistics, $er->averageTier));
        self::assertSame($baseline, $ratings->er($preparer->er()->statistics, 6));
        $short = $preparer->wn8()->input;
        self::assertNotNull($short);
        $short->statistics['damage_dealt'] *= 2;
        self::assertGreaterThan(1565, $ratings->wn8($short->statistics, $short->tankBattles, $short->expectedValues));
        $short->expectedValues[1]['expDamage'] *= 2;
        self::assertSame(1565, $ratings->wn8($short->statistics, $short->tankBattles, $short->expectedValues));
        $full = $preparer->wn8FromFull()->input;
        self::assertNotNull($full);
        $full->fullTanks[0]['statistics']['damage_dealt'] *= 2;
        self::assertGreaterThan(1565, $ratings->wn8FromFull($full->fullTanks, $full->expectedValues));
        self::assertSame(1565, $ratings->wn8($preparer->wn8()->input->statistics, $preparer->wn8()->input->tankBattles));
        $short->statistics['wins'] = 101;
        $this->expectException(InvalidInput::class);
        $ratings->wn8($short->statistics, $short->tankBattles, $short->expectedValues);
    }

    public function testMalformedFullCountersAreNotSilentlyIgnored(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], fullTanks: [['tank_id' => 1, 'all' => ['battles' => 100]]]);
        $this->expectException(InvalidInput::class);
        $preparer->wn8FromFull();
    }

    public function testAvailableModesAndMissingFullDataDoNotBlockReadyWn8(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $this->stats(), 'random' => $this->stats(), 'trees_cut' => 42, 'optional' => null]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            expectedValues: $this->table()
        );
        self::assertSame(['all'], $preparer->availableModes());
        self::assertSame(['all'], $preparer->availableModes('wn8'));
        self::assertSame([], $preparer->availableModes('er'));
        self::assertSame(['random'], $preparer->missingFullTankModes());
        self::assertFalse($preparer->diagnostics()['all']['er']['requiresFullTanks']);
        self::assertTrue($preparer->diagnostics()['random']['wn8']['requiresFullTanks']);
        $input = $preparer->wn8()->input;
        self::assertNotNull($input);
        self::assertSame(1565, (new WotRatings())->wn8($input->statistics, $input->tankBattles, $input->expectedValues));
    }

    public function testDefaultPrefersMatchingShortDataForEveryRatingAndForceSelectsFull(): void
    {
        $account = $this->stats();
        $fullStats = $this->stats(80);
        $fullStats['damage_dealt'] *= 2;
        $fullStats['capture_points'] *= 3;
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $account]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            [['tank_id' => 2, 'all' => $fullStats]],
            [1 => ['tier' => 4], 2 => ['tier' => 8]]
        );
        foreach (['er', 'wn6'] as $rating) {
            $short = $preparer->$rating();
            $full = $preparer->$rating(source: 'tankstats');
            self::assertSame('short', $short->source);
            self::assertSame(100, $short->statistics['battles']);
            self::assertSame(100000.0, $short->statistics['damage_dealt']);
            self::assertSame(4.0, $short->averageTier);
            self::assertSame('full', $full->source);
            self::assertSame(80, $full->statistics['battles']);
            self::assertSame(160000.0, $full->statistics['damage_dealt']);
            self::assertSame(8.0, $full->averageTier);
        }
        self::assertSame(240.0, $preparer->er(source: 'tankstats')->statistics['capture_points']);
        self::assertSame('short', $preparer->wn8()->input?->source);
        self::assertSame('full', $preparer->wn8(source: 'tankstats')->input?->source);
        self::assertSame(80, $preparer->wn8FromFull()->input?->statistics['battles']);
        self::assertSame('short', $preparer->diagnostics()['all']['er']['source']);
        self::assertSame('full', $preparer->diagnostics('tankstats')['all']['er']['source']);
    }

    public function testMismatchUsesFullCountersAndTierForEveryRating(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $this->stats()]],
            [['tank_id' => 1, 'statistics' => ['battles' => 99]]],
            [['tank_id' => 1, 'all' => $this->stats(20)], ['tank_id' => 2, 'all' => $this->stats(60)]],
            [1 => ['tier' => 4], 2 => ['tier' => 8]]
        );
        $ratings = new WotRatings();
        foreach (['er', 'wn6'] as $rating) {
            $input = $preparer->$rating();
            self::assertSame('full', $input->source);
            self::assertSame(80, $input->statistics['battles']);
            self::assertSame(7.0, $input->averageTier);
            self::assertSame($ratings->$rating($this->stats(80), 7), $ratings->$rating($input->statistics, $input->averageTier));
        }
        self::assertSame(80.0, $preparer->er()->statistics['capture_points']);
        self::assertSame(40.0, $preparer->wn6()->statistics['wins']);
        self::assertSame('full', $preparer->wn8()->input?->source);
        self::assertSame(['all'], $preparer->availableModes('er'));
        self::assertSame([], $preparer->missingFullTankModes());
    }

    public function testExplicitFullSourceDoesNotSilentlyUseShortData(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], [['tank_id' => 1, 'statistics' => ['battles' => 100]]]);
        self::assertSame('short', $preparer->wn8()->input?->source);
        self::assertNull($preparer->wn8(source: 'tankstats')->input);
        self::assertStringContainsString('requested', $preparer->wn8FromFull()->reason);
        self::assertSame([], $preparer->availableModes('wn8', 'tankstats'));
        $this->expectException(PreparationUnavailable::class);
        $preparer->er(source: 'tankstats');
    }

    public function testLateDetailedRecordsRefreshAvailabilityAndPreserveExistingInputs(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $this->stats(), 'random' => $this->stats()]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            catalogue: [1 => ['tier' => 6]]
        );
        $previous = $preparer->wn8()->input;
        self::assertSame(['random'], $preparer->missingFullTankModes());
        $preparer->setFullTankStatistics([['tank_id' => 1, 'all' => $this->stats(80), 'random' => $this->stats(60)]]);
        self::assertSame(['all', 'random'], $preparer->availableModes('wn8'));
        self::assertSame(['all', 'random'], $preparer->availableModes('er'));
        self::assertSame([], $preparer->missingFullTankModes());
        self::assertSame('short', $preparer->wn8()->input?->source);
        self::assertSame(60, $preparer->wn8('random')->input?->statistics['battles']);
        self::assertSame('full', $preparer->er('random')->source);
        $fullSnapshot = $preparer->wn8FromFull()->input;
        $preparer->setFullTankStatistics([['tank_id' => 1, 'all' => $this->stats(40)]]);
        self::assertSame(100, $previous?->statistics['battles']);
        self::assertSame(80, $fullSnapshot?->statistics['battles']);
        self::assertSame(40, $preparer->wn8FromFull()->input?->statistics['battles']);
        self::assertSame(['random'], $preparer->missingFullTankModes());
    }

    public function testLaterResponseRetainsFactoryIdentityWhenInfoOmitsAccountId(): void
    {
        $wrap = static fn (mixed $record): array => ['status' => 'ok', 'data' => [123 => $record]];
        $preparer = PlayerDataPreparer::fromApiResponses(123, $wrap(['statistics' => ['all' => $this->stats()]]), $wrap([]));
        $preparer->setFullTankStatsResponse($wrap([['account_id' => 123, 'tank_id' => 1, 'all' => $this->stats(80)]]));
        self::assertSame(80, $preparer->wn8()->input?->statistics['battles']);
        foreach ([['status' => 'error'], ['status' => 'ok', 'data' => [124 => []]], $wrap(null), $wrap([['account_id' => 124, 'tank_id' => 1, 'all' => $this->stats()]])] as $bad) {
            try {
                $preparer->setFullTankStatsResponse($bad);
                self::fail('Invalid later response must fail.');
            } catch (InvalidInput) {
                self::assertSame(80, $preparer->wn8()->input?->statistics['battles']);
            }
        }
    }

    public function testInvalidLaterTankRecordListDoesNotReplacePreviousData(): void
    {
        $preparer = new PlayerDataPreparer(['account_id' => 123, 'statistics' => ['all' => $this->stats()]], fullTanks: [['tank_id' => 1, 'all' => $this->stats(80)]]);
        foreach ([['tank_id' => 1], [false], [['tank_id' => 0]], [['tank_id' => 1], ['tank_id' => 1]], [['tank_id' => 1, 'account_id' => 124]]] as $bad) {
            try {
                $preparer->setFullTankStatistics($bad);
                self::fail('Invalid record list must fail.');
            } catch (InvalidInput) {
                self::assertSame(80, $preparer->wn8()->input?->statistics['battles']);
                self::assertTrue($preparer->diagnostics()['all']['wn8']['ready']);
            }
        }
    }

    public function testResponseSetterRequiresIdentityForExtractedPlayerRecord(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]]);
        $this->expectException(InvalidInput::class);
        $preparer->setFullTankStatsResponse(['status' => 'ok', 'data' => [123 => []]]);
    }

    public function testCatalogueEnvelopeSupportsCombatPreparation(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $this->stats()]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            catalogue: ['status' => 'ok', 'data' => [1 => ['tier' => 6]]]
        );
        self::assertSame(6.0, $preparer->er()->averageTier);
        self::assertSame(['all'], $preparer->availableModes('wn6'));
    }

    public function testFullOnlyCountersDoNotNeedUnrelatedAccountFields(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => ['battles' => 100]]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            [['tank_id' => 1, 'all' => $this->stats(80)]],
            [1 => ['tier' => 6]]
        );
        self::assertSame([], $preparer->availableModes());
        self::assertSame(['all'], $preparer->availableModes(source: 'tankstats'));
        self::assertSame(80, $preparer->er(source: 'tankstats')->statistics['battles']);
        self::assertSame(80, $preparer->wn6(source: 'tankstats')->statistics['battles']);
        self::assertSame(80, $preparer->wn8FromFull()->input?->statistics['battles']);
    }

    public function testIncompleteFullModeCoverageIsNeverPartiallySummed(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['random' => $this->stats()]],
            fullTanks: [['tank_id' => 1, 'random' => $this->stats(50)], ['tank_id' => 2, 'all' => $this->stats(50)]]
        );
        self::assertNull($preparer->wn8('random')->input);
        self::assertSame(['random', 'all'], $preparer->missingFullTankModes());
        self::assertSame([], $preparer->availableModes('wn8'));
    }

    public function testMalformedFullCountersAreDiagnosedSeparatelyFromMissingFullData(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], fullTanks: [['tank_id' => 1, 'all' => ['battles' => 100, 'wins' => 101]]]);
        self::assertSame([], $preparer->availableModes('wn8'));
        self::assertSame([], $preparer->missingFullTankModes());
        self::assertNotNull($preparer->diagnostics()['all']['wn8']['reason']);
        self::assertFalse($preparer->diagnostics()['all']['wn8']['ready']);
    }

    public function testMatchingShortDataDoesNotValidateUnusedFullCounters(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $this->stats()]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            [['tank_id' => 1, 'all' => ['battles' => 100]]],
            [1 => ['tier' => 6]]
        );
        self::assertSame(['all'], $preparer->availableModes('wn8'));
        self::assertSame(['all'], $preparer->availableModes('er'));
        self::assertSame([], $preparer->availableModes('wn8', 'tankstats'));
        self::assertSame('short', $preparer->wn8()->input?->source);
    }

    public function testSourceAndRatingSelectorsRejectUnknownValues(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]]);
        foreach ([static fn () => $preparer->wn8(source: 'short'), static fn () => $preparer->availableModes('wn7'), static fn () => $preparer->diagnostics('full')] as $call) {
            try {
                $call();
                self::fail('Unknown selector must fail.');
            } catch (InvalidInput $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testZeroBattleInputsStayCalculableWithExpectedValues(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats(0)]], fullTanks: [['tank_id' => 1, 'all' => $this->stats(0)]], expectedValues: $this->table());
        self::assertSame(['all'], $preparer->availableModes('er'));
        $ratings = new WotRatings();
        self::assertSame(0, $ratings->er($preparer->er()->statistics, $preparer->er()->averageTier));
        $full = $preparer->wn8FromFull()->input;
        self::assertNotNull($full);
        self::assertSame(0, $ratings->wn8FromFull($full->fullTanks, $full->expectedValues));
    }

    public function testDetailedModesCanBePreparedWhenAccountInfoOmitsThem(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => $this->stats()]],
            [['tank_id' => 1, 'statistics' => ['battles' => 100]]],
            [['tank_id' => 1, 'random' => $this->stats(80)]],
            [1 => ['tier' => 6]],
            $this->table()
        );
        self::assertSame(['all', 'random'], $preparer->availableModes('wn8'));
        self::assertSame(['all', 'random'], $preparer->availableModes('er'));
        self::assertSame('full', $preparer->er('random')->source);
        self::assertSame(80, $preparer->wn6('random', 'tankstats')->statistics['battles']);
        $input = $preparer->wn8('random')->input;
        self::assertNotNull($input);
        self::assertSame(1565, (new WotRatings())->wn8FromFull($input->fullTanks, $input->expectedValues));
    }

    public function testExplicitFullSourceIsIndependentOfInvalidAccountBattleCounter(): void
    {
        $preparer = new PlayerDataPreparer(
            ['statistics' => ['all' => ['battles' => 'broken']]],
            fullTanks: [['tank_id' => 1, 'all' => $this->stats(80)]],
            catalogue: [1 => ['tier' => 6]]
        );
        self::assertSame([], $preparer->availableModes());
        self::assertSame(['all'], $preparer->availableModes('wn8', 'tankstats'));
        self::assertSame(80, $preparer->er(source: 'tankstats')->statistics['battles']);
        self::assertSame(80, $preparer->wn8FromFull()->input?->statistics['battles']);
    }

    public function testExplicitZeroBattleFullDataDoesNotUsePlayedAccountCounters(): void
    {
        $preparer = new PlayerDataPreparer(['statistics' => ['all' => $this->stats()]], fullTanks: [['tank_id' => 1, 'all' => $this->stats(0)]], expectedValues: $this->table());
        self::assertNull($preparer->wn8()->input);
        $full = $preparer->wn8FromFull()->input;
        self::assertNotNull($full);
        self::assertSame(0, $full->statistics['battles']);
        self::assertSame(0, (new WotRatings())->wn8FromFull($full->fullTanks, $full->expectedValues));
    }
}
