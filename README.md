# WotRatings

PHP 8.5 library for preparing World of Tanks statistics and calculating ER, WN6, WN8 and legacy ratings, with multiple battle modes and editable calculation inputs.

Composer package: `edrard/wotratings`. Namespace: `Edrard\WotRatings`. [Source repository](https://github.com/Edrard/WotRatings).

## Installation

Until a tagged release is available, install the main branch through a Composer VCS repository:

```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/Edrard/WotRatings"}],
    "require": {"edrard/wotratings": "dev-main"}
}
```

For local development, use a Composer path repository pointing to this checkout instead:

```json
{
    "repositories": [{"type": "path", "url": "../WotRatings", "options": {"versions": {"edrard/wotratings": "dev-main"}}}],
    "require": {"edrard/wotratings": "dev-main"}
}
```

Run `composer update edrard/wotratings` in the consuming project. Its runtime needs PHP 8.5 or later within the PHP 8 series. The package has no runtime dependency beyond PHP. In examples below, load your Composer autoloader first.

## Classes

- `PlayerDataPreparer` accepts decoded `wot/account/info`, `wot/account/tanks`, optional `wot/tanks/stats`, `wot/encyclopedia/vehicles` metadata and an optional WN8 expected table. It reports data readiness by mode and rating, selects a coherent source and returns editable calculation inputs.
- `WotRatings` calculates ER, WN6 and WN8 from prepared, provider-independent counters. `wn8()` uses account counters with short tank battle counts; `wn8FromFull()` sums complete tank counters.
- `LegacyRatings` implements historical WN7, Broni, Noobmeter and the old xeff/xwn6/xwn7/xnm conversions.

The application supplies responses, metadata and expected tables. Preparation and calculation operate offline.

## Raw player data to ratings

```php
use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;

// All variables below are decoded arrays supplied by your application.
$preparer = PlayerDataPreparer::fromApiResponses(
    accountId: $accountId,
    accountInfo: $accountInfoResponse,
    accountTanks: $accountTanksResponse,
    tankStats: $tankStatsResponse, // optional: null if not collected
    catalogue: $vehiclesResponse, // encyclopedia/vehicles envelope or tank-ID metadata map
    expectedValues: $expectedDocument,
);

$ratings = new WotRatings();
$ratings->setExpectedValues($expectedDocument);

$modes = $preparer->availableModes();       // ready for at least one rating
$wn8Modes = $preparer->availableModes('wn8');
$diagnostics = $preparer->diagnostics();    // ready/reason/requiresFullTanks/source by mode and rating
$missing = $preparer->missingFullTankModes(); // WN8 modes needing usable full tank data

$er = $preparer->er('all');
$erValue = $ratings->er($er->statistics, $er->averageTier);

$wn6 = $preparer->wn6('all');
$wn6Value = $ratings->wn6($wn6->statistics, $wn6->averageTier);

$prepared = $preparer->wn8('all');
if ($prepared->input === null) {
    // Data cannot be prepared; $prepared->reason explains why.
    $reason = $prepared->reason;
} else {
    $input = $prepared->input;
    $wn8Value = $input->source === 'full'
        ? $ratings->wn8FromFull($input->fullTanks, $input->expectedValues)
        : $ratings->wn8($input->statistics, $input->tankBattles, $input->expectedValues);
}

// Explicitly select full tank data even if short totals match:
$full = $preparer->wn8('random', source: 'tankstats');
if ($full->input !== null) {
    $wn8Random = $ratings->wn8FromFull($full->input->fullTanks, $full->input->expectedValues);
}
```

`fromApiResponses()` requires WG `status: ok` envelopes and coverage of the requested account ID. An absent player is invalid input. The constructor also accepts extracted records directly: `new PlayerDataPreparer($player, $shortTanks, $fullTanks, $catalogue, $expectedTable)`.

Account modes live under `player.statistics[mode]`. Full tank modes live under each tank's `[mode]`, as returned by `tanks/stats`. A mode omitted by account/info can still be prepared from complete full tank mode counters. Flat `account/tanks` statistics describe `all` only; they cannot establish random/team mode coverage. Explicit mode-keyed short statistics are also accepted. The selected mode is used for all counters and tank battle weights.

## Sources and data readiness

All three preparation methods accept `source: 'auto'` (default) or `source: 'tankstats'`.

| Selected data | `auto` | `tankstats` |
| --- | --- | --- |
| Short tank mode is covered and its battles match account totals | Account counters and short tank counts, even if full data was supplied | Full tank counters and counts |
| Short totals differ, or the short mode is unavailable | Full tank counters and counts, if usable | Full tank counters and counts |
| Needed full data is missing or has incomplete mode coverage | Unavailable | Unavailable |

Full preparation sums the selected tank mode's own counters. It uses those same tank battle counts for average tier and expected values; it does not mix account counters with full tank weights or require full battles to match account battles. An explicit `tankstats` request is independent of account counters and never falls back to short data. `wn8FromFull($mode)` on the preparer is a convenience alias for `wn8($mode, 'tankstats')`.

An empty full dataset cannot establish coverage. In `auto`, zero full battles cannot replace a played account mode. An explicit `tankstats` request can prepare a zero-battle mode when complete zero counters were supplied.

ER and WN6 inputs include battle-weighted average tier from the selected vehicle source and catalogue metadata, rounded to two decimal places. Supply a decoded encyclopedia/vehicles response or a tank-ID map containing `tier` or `level`. WN8 does not need the catalogue. Additional unrelated fields are ignored; each formula requires only its own counters.

`availableModes('er'|'wn6'|'wn8')` returns an array of ready mode names. Without a rating, it returns modes ready for at least one rating. `diagnostics()` is available immediately after construction. For each mode and rating it exposes `ready`, `reason`, `requiresFullTanks` and the selected `source` (`short`, `full` or null). Ancillary statistics and null modes are ignored. Both methods accept an optional source selector, for example `availableModes('er', 'tankstats')`.

Readiness checks data preparation. A WN8 table must still be supplied either with the prepared input or through the calculator. Used tank entries are validated at calculation time. `missingFullTankModes()` returns mode names, not a global failure flag: an unavailable random mode does not prevent calculating a ready all mode. Malformed counters are reported separately from missing full data.

WN8 returns `Wn8Preparation` with either an `input` or an unavailable `reason`. ER and WN6 throw `Exception\PreparationUnavailable` when the needed source is absent or unusable. Invalid records, fields or selectors raise `Exception\InvalidInput`; arithmetic overflow raises `Exception\CalculationFailed`. Use diagnostics to inspect readiness before requesting inputs.

## Add full statistics later

Start with account/info and account/tanks, then supply tanks/stats when your application obtains it:

```php
$preparer = PlayerDataPreparer::fromApiResponses(
    $accountId, $accountInfoResponse, $accountTanksResponse,
    catalogue: $vehiclesResponse,
    expectedValues: $expectedDocument,
);
$missingModes = $preparer->missingFullTankModes();

// Your application obtains the response using its own transport.
$preparer->setFullTankStatsResponse($tankStatsResponse);

// Alternatively, supply the already-extracted list for this player:
// $preparer->setFullTankStatistics($fullTankRecords);

$modes = $preparer->availableModes('wn8'); // refreshed immediately
$erFull = $preparer->er('random', source: 'tankstats');
$erValue = $ratings->er($erFull->statistics, $erFull->averageTier);
```

The methods replace the current full dataset, preserving the short-first default. Previously returned inputs remain independent snapshots. The response method verifies WG status and account coverage; extracted records verify list shape, unique tank IDs and supplied account IDs. Invalid structure or identity leaves the previous full dataset intact. Counter completeness is reflected in the refreshed diagnostics. When using the extracted-record constructor, include `player.account_id` to use the later envelope method. The factory retains the requested ID even if account/info omits that field.

## Inspect and edit prepared data

Preparation never calculates a rating automatically. The returned input objects expose mutable arrays and fields. Edit them before calling the calculator:

```php
$er = $preparer->er('all');
$er->statistics['damage_dealt'] = $adjustedDamage;
$er->averageTier = $adjustedAverageTier;
$value = $ratings->er($er->statistics, $er->averageTier);

$input = $preparer->wn8('all')->input;
if ($input !== null) {
    $input->statistics['damage_dealt'] = $adjustedDamage;
    // Optional: edit the expected table supplied during preparation.
    $input->expectedValues = $anotherTable;
    $value = $ratings->wn8($input->statistics, $input->tankBattles, $input->expectedValues);
}

$full = $preparer->wn8FromFull('random')->input;
if ($full !== null) {
    $full->fullTanks[0]['statistics']['damage_dealt'] = $adjustedTankDamage;
    $value = $ratings->wn8FromFull($full->fullTanks, $full->expectedValues);
}
```

`CombatRatingInput` exposes `statistics`, `averageTier`, `mode` and `source`. `Wn8Input` exposes `statistics`, `tankBattles`, `fullTanks`, `mode`, `source` and an optional normalized `expectedValues` table keyed by tank ID.

For full WN8 calculations, edit `fullTanks`; the calculator's `wn8FromFull()` recomputes totals. Aggregate `statistics` and `tankBattles` are also exposed for direct `wn8()` calls; they are snapshots and do not automatically synchronize with edited full rows. Keep aggregate battle totals and tank counts consistent when editing them. Each preparation call returns independent data. Editing prepared inputs does not alter source records, other inputs or the calculator's installed table. Calculations validate edited values again, including battle sums and expected entries.

The library has no 300/20,000 battle eligibility policy. The caller decides whether to calculate, collect additional tanks or publish a result. Zero-battle prepared calculations return zero; zero-battle WN8 still requires an expected table.

## Direct calculations and expected values

```php
$statistics = [
    'battles' => 100, 'wins' => 50, 'damage_dealt' => 100000,
    'frags' => 100, 'spotted' => 100, 'dropped_capture_points' => 100,
    'capture_points' => 100,
];
$tankBattles = [['tank_id' => 1, 'battles' => 100]];
$wn8 = $ratings->wn8($statistics, $tankBattles);
$override = $ratings->wn8($statistics, $tankBattles, $anotherTable);
$full = $ratings->wn8FromFull([
    ['tank_id' => 1, 'statistics' => $statistics],
], $anotherTable);
```

Statistics are flat totals, not per-battle averages. Supply only the fields required by the chosen formula. Battles are nonnegative integers; other required counters are finite nonnegative numbers. Wins cannot exceed battles. Core short-tank WN8 requires unique tank IDs and a battle sum matching the supplied statistics; the preparer resolves mismatches before calling it.

Expected input can be a decoded XVM document with `data`, a list of rows containing `IDNum`, or a tank-ID map. Required fields: `expDamage`, `expSpot`, `expFrag`, `expDef`, `expWinRate`. Values must be finite and positive; expWinRate uses percent, at most 100. Empty tables, conflicting/duplicate IDs and malformed rows are rejected.

`setExpectedValues()` validates before replacing the instance table. A non-null method override is used only for that call, including full WN8, without installing or changing the default. Passing null uses the installed default. An explicit empty override fails instead of falling back. Without either table, WN8 throws `MissingExpectedValues`; missing used tank entries raise the same exception. ER and WN6 work without a table. Overflow raises `CalculationFailed`.

ER, WN6 and WN8 return rounded integers. WN6 preserves the owner's convention of rounding win percentage to one decimal. Different snapshots, expected versions, modes or rounding conventions can produce different published ratings.

## Historical methods

The instance methods `$legacy->wn7($statistics, $averageTier)`, `$legacy->broni($statistics)` and `$legacy->noobmeter($statistics, $tankBattles, $averageTier)` return rounded integers. A WN6-prepared input also contains the fields needed by WN7. Broni additionally needs total `xp`. Noobmeter needs vehicle rows with battles and historical `nominal_damage` supplied by the caller. Its win fraction and scaled nominal damage follow the historical formula.

`xeff()`, `xwn6()` (old rating_xwn), `xwn7()` and `xnm()` return historical 0–100 floating scales. They are not modern XVM percentile conversions.

## Development and verification

```sh
composer install
composer test
composer analyse
composer format:check

# Download references separately; src/ contains no network calls.
mkdir -p .reference
curl --fail https://static.modxvm.com/wn8-data-exp/json/wg/wn8exp.json -o .reference/wn8exp.json
php tools/check-expected-values.php .reference/wn8exp.json
php examples/player-ratings.php
```

Regular tests are offline and use synthetic data. Historical golden values were generated from the owner's read-only `Old_work/soft/statistic/new_serv_distrib/rating.php`; the fixture records its SHA-256. The separate expected-table check validates every row using synthetic counters with all five ratios equal to one (WN8 1565); it is not a leaderboard comparison.

## Provenance and consumption

MIT license. Formula provenance: the owner's historical rating.php and existing WgParser calculations. WN8 expected-table provenance: the official XVM URL above; external data is not relicensed or bundled with runtime code. Intended consumer: the World of Tanks statistics collector. WgParser integration is maintained separately.
