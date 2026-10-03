<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;

$statsFor = static fn (int $battles): array => [
    'battles' => $battles, 'wins' => $battles / 2, 'damage_dealt' => $battles * 1000,
    'frags' => $battles, 'spotted' => $battles, 'dropped_capture_points' => $battles,
    'capture_points' => $battles,
];
$expected = [1 => ['expDamage' => 1000, 'expSpot' => 1, 'expFrag' => 1, 'expDef' => 1, 'expWinRate' => 50]];
$wrap = static fn (array $record): array => ['status' => 'ok', 'data' => [123 => $record]];
$preparer = PlayerDataPreparer::fromApiResponses(
    123,
    $wrap(['statistics' => ['all' => $statsFor(100), 'random' => $statsFor(100)]]),
    $wrap([['tank_id' => 1, 'statistics' => ['battles' => 100]]]),
    catalogue: ['status' => 'ok', 'data' => [1 => ['tier' => 6]]],
    expectedValues: $expected
);
$ratings = new WotRatings();
$ratings->setExpectedValues($expected);

// A missing random mode does not block the ready all mode.
$availableBefore = $preparer->availableModes('wn8');
$missingBefore = $preparer->missingFullTankModes();
$er = $preparer->er();
$wn6 = $preparer->wn6();
$short = $preparer->wn8()->input ?? throw new RuntimeException('Synthetic short inputs unavailable.');

// The application supplies a later response. Short-first selection is preserved for all.
$preparer->setFullTankStatsResponse($wrap([
    ['tank_id' => 1, 'all' => $statsFor(80), 'random' => $statsFor(60)],
]));
$full = $preparer->wn8('random', source: 'tankstats')->input ?? throw new RuntimeException('Synthetic full inputs unavailable.');
$forcedEr = $preparer->er(source: 'tankstats');

// Inspect/edit an independent input, then pass its fields to the calculator.
$edited = $preparer->wn8()->input ?? throw new RuntimeException('Synthetic editable inputs unavailable.');
$edited->statistics['damage_dealt'] *= 2;

echo json_encode([
    'availableBefore' => $availableBefore,
    'missingBefore' => $missingBefore,
    'availableAfter' => $preparer->availableModes('wn8'),
    'missingAfter' => $preparer->missingFullTankModes(),
    'defaultSource' => $preparer->wn8()->input?->source,
    'forcedErSource' => $forcedEr->source,
    'forcedErBattles' => $forcedEr->statistics['battles'],
    'er' => $ratings->er($er->statistics, $er->averageTier),
    'wn6' => $ratings->wn6($wn6->statistics, $wn6->averageTier),
    'wn8Short' => $ratings->wn8($short->statistics, $short->tankBattles, $short->expectedValues),
    'wn8FullRandom' => $ratings->wn8FromFull($full->fullTanks, $full->expectedValues),
    'wn8Edited' => $ratings->wn8($edited->statistics, $edited->tankBattles, $edited->expectedValues),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
