<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Edrard\WotRatings\WotRatings;

// Offline development verification. The caller downloads the public XVM table separately.
if (count($argv) !== 2) {
    fwrite(STDERR, "Usage: php tools/check-expected-values.php /path/to/wn8exp.json\n");
    exit(2);
}
$body = file_get_contents($argv[1]);
if ($body === false) {
    throw new RuntimeException('Cannot read reference file.');
}
$payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
    throw new RuntimeException('Expected XVM document with data rows.');
}
$ratings = new WotRatings();
$ratings->setExpectedValues($payload['data']);
$checked = 0;
foreach ($payload['data'] as $row) {
    // Synthetic counters exactly equal to expected values: all five ratios must equal one.
    $statistics = ['battles' => 100, 'wins' => $row['expWinRate'], 'damage_dealt' => $row['expDamage'] * 100,
        'frags' => $row['expFrag'] * 100, 'spotted' => $row['expSpot'] * 100,
        'dropped_capture_points' => $row['expDef'] * 100];
    $actual = $ratings->wn8($statistics, [['tank_id' => $row['IDNum'], 'battles' => 100]]);
    if ($actual !== 1565) {
        throw new RuntimeException('Expected-ratio reference check failed.');
    }
    ++$checked;
}
echo json_encode(['source' => 'https://static.modxvm.com/wn8-data-exp/json/wg/wn8exp.json',
    'sha256' => hash('sha256', $body), 'header' => $payload['header'] ?? null,
    'rowsVerified' => $checked, 'syntheticRatiosOfOneWn8' => 1565], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
