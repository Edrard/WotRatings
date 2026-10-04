<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Edrard\WotRatings\LegacyRatings;
use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;

$data = require __DIR__.'/player-data.php';
$preparer = new PlayerDataPreparer(catalogue: $data['catalogue']);
$ratings = new WotRatings();
$ratings->setExpectedValues($data['expected']);

$status = $preparer->setData($data['player'], $data['short']);
$output = [
    'status' => $status,
    'reportBeforeFull' => $preparer->report,
    'erInput' => $preparer->er('all'),
    'wn6Input' => $preparer->wn6('all'),
    'wn8Input' => $preparer->wn8('all'),
    'er' => $ratings->er(...$preparer->er('all')),
    'wn6' => $ratings->wn6(...$preparer->wn6('all')),
    'wn8' => $ratings->wn8(...$preparer->wn8('all')),
];

// Collect missing modes before calculation; the library performs no requests.
$preparer->setFullTankStatsResponse($data['tankStatsResponse']);
$output['availableAfterFull'] = $preparer->availableModes('wn8');
$output['wn8FullInput'] = $preparer->wn8FromFull('random');
$output['wn8Random'] = $ratings->wn8(...$preparer->wn8('random'));
$output['wn8DefaultAll'] = $ratings->wn8(...$preparer->wn8('all'));
$output['wn8ForcedAll'] = $ratings->wn8(...$preparer->wn8('all', source: 'tankstats'));
$output['wn8ForcedFullRows'] = $ratings->wn8FromFull(...$preparer->wn8FromFull('all'));

$edited = $preparer->wn8('all');
$edited['statistics']['damage_dealt'] *= 2;
$output['wn8Edited'] = $ratings->wn8(...$edited);
$output['wn8Unchanged'] = $ratings->wn8(...$preparer->wn8('all'));

// A new ingestion replaces the previous player and full dataset.
$shortMismatch = $data['short'];
$shortMismatch[0]['statistics']['battles'] = 99;
$output['mismatchStatus'] = $preparer->setData($data['player'], $shortMismatch);
$output['mismatches'] = $preparer->battleCountMismatches();
$output['missingWn8Modes'] = $preparer->missingFullTankModes('wn8');
$preparer->setFullTankStatistics($data['full']);
$output['wn8AfterMismatchFallback'] = $ratings->wn8(...$preparer->wn8('all'));

$legacy = new LegacyRatings();
$statistics = $data['player']['statistics']['all'];
$output['legacy'] = [
    'wn7' => $legacy->wn7($statistics, 6),
    'broni' => $legacy->broni($statistics + ['xp' => 50000]),
    'noobmeter' => $legacy->noobmeter($statistics, [['battles' => 100, 'nominal_damage' => 1000]], 6),
    'xeff' => $legacy->xeff(1000),
    'xwn6' => $legacy->xwn6(1000),
    'xwn7' => $legacy->xwn7(1000),
    'xnm' => $legacy->xnm(1000),
];

echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
