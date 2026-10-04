# WotRatings

PHP 8.5 library for preparing World of Tanks player statistics and calculating ER, WN6, WN8 and historical ratings. Preparation returns editable associative arrays that can be unpacked directly into calculator methods.

Package: `edrard/wotratings`. Namespace: `Edrard\WotRatings`. [Source](https://github.com/Edrard/WotRatings). MIT license.

## Installation

Until a tagged release is available, add the main branch through a Composer VCS repository:

```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/Edrard/WotRatings"}],
    "require": {"edrard/wotratings": "dev-main"}
}
```

For a local checkout, use a path repository instead:

```json
{
    "repositories": [{"type": "path", "url": "../WotRatings", "options": {"versions": {"edrard/wotratings": "dev-main"}}}],
    "require": {"edrard/wotratings": "dev-main"}
}
```

Run `composer update edrard/wotratings` in the consuming application. Runtime: PHP `^8.5`, with no other runtime dependencies. The application obtains WG responses, vehicle metadata and expected values; the library performs no network requests.

## Workflow

1. Configure a reusable `PlayerDataPreparer` with vehicle metadata and, optionally, expected values.
2. Load one player's data. The load method immediately checks comparable battle counts and builds the report.
3. Inspect missing modes for the requested rating; provide full tank statistics when needed.
4. Choose a mode from `availableModes()` and request its prepared arguments.
5. Inspect or edit those arguments, then pass them to `WotRatings` using `...`.
6. Load the next player into the same preparer. Player data, full statistics, report and caches are replaced; configuration is retained.

`WotRatings` calculates ER, WN6 and WN8. `LegacyRatings` contains WN7, Broni, Noobmeter and historical scale conversions. Neither calculator accepts a preparer object or automatically collects missing data.

## Load data: choose the input format

The following examples use the executable synthetic dataset in [examples/player-data.php](examples/player-data.php): account `123`, one tier-6 tank, 100 account battles in both `all` and `random`, and 100 short tank battles in `all`. Full data contains 80 `all` battles with 160000 damage, and 60 `random` battles with 60000 damage. The expected table contains one tank with damage 1000, frags/spots/defence 1, and win rate 50 percent.

The examples below run in order from the package root after `composer install`. In an application, supply your own decoded arrays instead of this example file. An API response here means a PHP array obtained by decoding JSON, not a JSON string or HTTP response object.

All three loading methods perform the same immediate battle-count and readiness checks. Choose the method by the data you already have and whether you want to reuse an existing preparer:

| Method | Input | Object lifecycle | Return value |
| --- | --- | --- | --- |
| `setData($player, $shortTanks, $fullTanks = null)` | One player's records, already selected from `data[$accountId]` | Replaces data in the existing preparer | Battle-count status: `bool` |
| `setApiResponses($accountId, $accountInfo, $accountTanks, $tankStats = null)` | Whole decoded WG responses, including `status` and `data` | Selects the player by ID and replaces data in the existing preparer | The same battle-count status: `bool` |
| `fromApiResponses($accountId, $accountInfo, $accountTanks, $tankStats = null, $catalogue = [], $expectedValues = null)` | Whole decoded WG responses and optional configuration | Creates and loads a new preparer | `PlayerDataPreparer` object |

### What a decoded API response contains

The response contains a `status` and a `data` map keyed by account ID. A request may return multiple players; loading selects only the requested player. For account/info, that entry is a player record. For account/tanks and tanks/stats, that entry is a list of tank records.

```php
require 'vendor/autoload.php';

use Edrard\WotRatings\LegacyRatings;
use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;

$data = require 'examples/player-data.php';
$accountId = 123;
$accountInfo = $data['accountInfoResponse'];
$accountTanks = $data['accountTanksResponse'];
$tankStats = $data['tankStatsResponse'];

echo json_encode([
    'accountInfo' => $accountInfo,
    'accountTanks' => $accountTanks,
]);
```

Decoded input arrays, shown as JSON:

```json
{
  "accountInfo": {
    "status": "ok",
    "data": {
      "123": {
        "account_id": 123,
        "statistics": {
          "all": {
            "battles": 100,
            "wins": 50,
            "damage_dealt": 100000,
            "frags": 100,
            "spotted": 100,
            "capture_points": 100,
            "dropped_capture_points": 100
          },
          "random": {
            "battles": 100,
            "wins": 50,
            "damage_dealt": 100000,
            "frags": 100,
            "spotted": 100,
            "capture_points": 100,
            "dropped_capture_points": 100
          }
        }
      }
    }
  },
  "accountTanks": {
    "status": "ok",
    "data": {
      "123": [
        {"account_id": 123, "tank_id": 1, "statistics": {"battles": 100}}
      ]
    }
  }
}
```

### setData: select the player's records yourself

Use `setData()` when you already have the player record and tank lists, for example from a cache, or want to select them from API responses yourself. The outer `status` and `data` fields are not passed to this method. Your application validates API success and account coverage before selecting these entries.

```php
$preparer = new PlayerDataPreparer(catalogue: $data['catalogue']);
$ratings = new WotRatings();
$ratings->setExpectedValues($data['expected']);

// Select one player from each response. These are ordinary array lookups.
$player = $accountInfo['data'][$accountId];
$shortTanks = $accountTanks['data'][$accountId];

$status = $preparer->setData($player, $shortTanks);
echo json_encode([
    'status' => $status,
    'availableWn8Modes' => $preparer->availableModes('wn8'),
    'missingWn8Modes' => $preparer->missingFullTankModes('wn8'),
]);
```

Response:

```json
{"status":true,"availableWn8Modes":["all"],"missingWn8Modes":["random"]}
```

The account and short tank totals both contain 100 `all` battles, so status is true. Random account counters exist, but short tank records cover only all, so random requires full tank data. The same applies to ER and WN6: their random average tier requires random tank battle counts.

Full statistics can be provided immediately as the third argument: select `$tankStats['data'][$accountId]` and pass that list. Omitting it starts a new player snapshot without full statistics, clearing any full data from the preceding load.

### setApiResponses: let the preparer select the player's records

Use `setApiResponses()` when you have whole decoded responses. Pass those arrays unchanged, together with the account ID. The method checks API status, verifies the requested account is present, selects `data[$accountId]` from each response and calls the same record-loading logic as setData.

```php
$apiPreparer = new PlayerDataPreparer(catalogue: $data['catalogue']);
$apiStatus = $apiPreparer->setApiResponses(
    $accountId,
    $accountInfo,
    $accountTanks,
);
echo json_encode([
    'status' => $apiStatus,
    'availableWn8Modes' => $apiPreparer->availableModes('wn8'),
    'missingWn8Modes' => $apiPreparer->missingFullTankModes('wn8'),
    'sameReportAsSetData' => $apiPreparer->report === $preparer->report,
]);
```

Response:

```json
{"status":true,"availableWn8Modes":["all"],"missingWn8Modes":["random"],"sameReportAsSetData":true}
```

The prepared data and report are equivalent to the setData example. If full statistics are already available, pass the whole `$tankStats` response as the fourth argument. If they are not available, omit the argument or pass null; a supplied response whose selected account record is null is invalid.

### fromApiResponses: create and load a new preparer

This static factory is a convenience for construction plus setApiResponses. It returns the new preparer, not a boolean status. Inspect its report to see the immediate checks. Use either setter to reuse an existing instance for the next player; use the factory when a separate instance is useful.

```php
$created = PlayerDataPreparer::fromApiResponses(
    $accountId,
    $accountInfo,
    $accountTanks,
    catalogue: $data['catalogue'],
);
echo json_encode([
    'availableWn8Modes' => $created->availableModes('wn8'),
    'missingWn8Modes' => $created->missingFullTankModes('wn8'),
    'sameReportAsSetData' => $created->report === $preparer->report,
]);
```

Response:

```json
{"availableWn8Modes":["all"],"missingWn8Modes":["random"],"sameReportAsSetData":true}
```

The factory adds no separate preparation algorithm. These two forms are equivalent in configuration, loaded data and report; they create separate objects:

```php
// Convenience factory: construct and load, returning the new object.
$fromFactory = PlayerDataPreparer::fromApiResponses(
    $accountId,
    $accountInfo,
    $accountTanks,
    catalogue: $data['catalogue'],
);

// The same steps written explicitly: the setter returns a boolean.
$manual = new PlayerDataPreparer(catalogue: $data['catalogue']);
$manualStatus = $manual->setApiResponses($accountId, $accountInfo, $accountTanks);

echo json_encode([
    'factoryReturnsPreparer' => $fromFactory instanceof PlayerDataPreparer,
    'setterReturnsStatus' => $manualStatus,
    'differentObjects' => $fromFactory !== $manual,
    'sameReport' => $fromFactory->report === $manual->report,
]);
```

Response:

```json
{"factoryReturnsPreparer":true,"setterReturnsStatus":true,"differentObjects":true,"sameReport":true}
```

For processing many players with one configured preparer, construct it once and call setData or setApiResponses for each player. The factory is optional convenience when creating a new instance, not a required loading step.

### What the loading status means

**The returned boolean concerns battle-count mismatches only.** False means at least one comparable account/short-tank mode has different totals. True means no numeric mismatch was detected. Missing mode coverage does not turn this status into false. Malformed counters cannot establish a numeric comparison and appear in diagnostics; true is not a guarantee that every rating can be prepared.

Comparisons use the same mode in account and short tank data. Flat WG account/tanks records cover only `all`; `random` is not compared against those totals. Empty short records cannot establish coverage of a played mode. A zero-battle account with an empty short list can establish a zero-battle `all` input.

Invalid envelope, record-list shape, duplicate tank IDs or mismatched supplied account IDs raises `InvalidInput` before replacing the current player. Missing or invalid calculation counters are recorded by the immediate readiness analysis. These checks happen during loading, before any call to `er()`, `wn6()` or `wn8()`.

## Report and mode discovery

The report is readable immediately after loading and refreshed after full statistics are replaced. External code cannot assign to `$preparer->report`; a local copy can be inspected or annotated.

```php
echo json_encode($preparer->report);
```

Response:

```json
{
  "battleCountMismatches": [],
  "missingFullTankModes": {
    "er": [
      "random"
    ],
    "wn6": [
      "random"
    ],
    "wn8": [
      "random"
    ]
  },
  "diagnostics": {
    "all": {
      "er": {
        "ready": true,
        "reason": null,
        "issue": null,
        "requiresFullTanks": false,
        "source": "short"
      },
      "wn6": {
        "ready": true,
        "reason": null,
        "issue": null,
        "requiresFullTanks": false,
        "source": "short"
      },
      "wn8": {
        "ready": true,
        "reason": null,
        "issue": null,
        "requiresFullTanks": false,
        "source": "short"
      }
    },
    "random": {
      "er": {
        "ready": false,
        "reason": "Mode random lacks short coverage or required counters; full statistics were not supplied.",
        "issue": "missing_mode_data",
        "requiresFullTanks": true,
        "source": null
      },
      "wn6": {
        "ready": false,
        "reason": "Mode random lacks short coverage or required counters; full statistics were not supplied.",
        "issue": "missing_mode_data",
        "requiresFullTanks": true,
        "source": null
      },
      "wn8": {
        "ready": false,
        "reason": "Mode random lacks short coverage or required counters; full statistics were not supplied.",
        "issue": "missing_mode_data",
        "requiresFullTanks": true,
        "source": null
      }
    }
  }
}
```

The report has three independent parts: numeric `battleCountMismatches`, missing coverage grouped by rating in `missingFullTankModes`, and per-mode/per-rating `diagnostics`.

Modes are discovered from supplied account statistics and full tank records containing a `battles` field. Ancillary scalars and null optional modes are ignored. The preparer cannot infer a mode omitted from every supplied source. Request the desired account mode through the API or supply its full tank statistics so it can be discovered.

### Available modes

`availableModes(?string $rating = null, string $source = 'auto'): array` returns a list of modes ready for the specified rating. Omitting the rating returns modes ready for at least one of ER, WN6 or WN8.

```php
echo json_encode([
    'any' => $preparer->availableModes(),
    'er' => $preparer->availableModes('er'),
    'wn6' => $preparer->availableModes('wn6'),
    'wn8' => $preparer->availableModes('wn8'),
    'forcedFullWn8' => $preparer->availableModes('wn8', 'tankstats'),
]);
```

Response before full data:

```json
{"any":["all"],"er":["all"],"wn6":["all"],"wn8":["all"],"forcedFullWn8":[]}
```

Readiness concerns preparation. WN8 still needs an expected table on the calculator or in its argument array, and entries for every played tank are checked when calculating. ER and WN6 also need vehicle tier metadata to prepare average tier.

### Missing mode data, by rating

`missingFullTankModes(string $rating): array` requires `er`, `wn6` or `wn8` and returns missing mode/required-counter coverage for that rating under automatic source selection.

```php
echo json_encode([
    'er' => $preparer->missingFullTankModes('er'),
    'wn6' => $preparer->missingFullTankModes('wn6'),
    'wn8' => $preparer->missingFullTankModes('wn8'),
]);
```

Response:

```json
{"er":["random"],"wn6":["random"],"wn8":["random"]}
```

These lists can differ: full rows without capture_points can prepare WN6/WN8 but cannot prepare ER. Invalid numeric values or missing catalogue metadata are diagnosed separately. A battle-count mismatch is reported through its dedicated method, not inserted into the missing-coverage list as though the short mode were absent. If both a mismatch and unavailable full fallback affect a mode, diagnostics identifies the mismatch and explains the unusable fallback; full-source diagnostics can inspect that coverage independently.

### Diagnostics

`diagnostics(string $source = 'auto'): array` returns the complete mode/rating map shown in the report. A selected entry looks like this:

```php
echo json_encode($preparer->diagnostics()['random']['wn8']);
```

Response:

```json
{"ready":false,"reason":"Mode random lacks short coverage or required counters; full statistics were not supplied.","issue":"missing_mode_data","requiresFullTanks":true,"source":null}
```

Each entry contains `ready`, human-readable `reason`, machine-readable `issue`, `requiresFullTanks`, and actual selected `source` (`short`, `full`, or null). Issue is null for ready inputs, otherwise `missing_mode_data`, `battle_count_mismatch`, `invalid_input` or `calculation_failed`. Use issue for branching; message wording is descriptive.

An unavailable mode does not block other ready modes. `requiresFullTanks` is local to its mode and rating, not a global failure flag. An unknown rating/source selector raises `InvalidInput`.

## Provide full statistics before calculation

Use one of these methods to add full statistics to the loaded player without replacing the account and short tank data. The difference is the same as with the two loading setters:

| Method | What to pass | Return value |
| --- | --- | --- |
| `setFullTankStatistics(array $fullTanks)` | The selected tank list: `$tankStats['data'][$accountId]` | `void` |
| `setFullTankStatsResponse(array $tankStats)` | The whole decoded tanks/stats response, including status/data | `void` |

Both replace the current full list, refresh readiness/report and invalidate dependent caches immediately. The response setter selects the current player's ID, which must already be known from loading. It checks the response and then calls setFullTankStatistics; it does not perform additional calculations or requests.

```php
$preparer->setFullTankStatsResponse($data['tankStatsResponse']);
echo json_encode([
    'available' => $preparer->availableModes('wn8'),
    'missing' => $preparer->missingFullTankModes('wn8'),
]);
```

Response:

```json
{"available":["all","random"],"missing":[]}
```

These equivalent examples use the two separate preparers loaded above:

```php
// Select the list yourself:
$apiPreparer->setFullTankStatistics($tankStats['data'][$accountId]);

// Or let the preparer select it from the response:
$created->setFullTankStatsResponse($tankStats);

echo json_encode([
    'fromRecords' => $apiPreparer->availableModes('wn8'),
    'fromResponse' => $created->availableModes('wn8'),
    'sameReport' => $apiPreparer->report === $created->report,
]);
```

Response:

```json
{"fromRecords":["all","random"],"fromResponse":["all","random"],"sameReport":true}
```

A valid replacement can still lack required counters; those gaps are reported by rating. Invalid structure or identity leaves the previous full dataset intact. Already returned arrays remain independent snapshots.

### Source selection

Every `er()`, `wn6()` and `wn8()` preparation call accepts a mode and `source: 'auto'` or `source: 'tankstats'`. `random` is a battle mode; `tankstats` is a source selector.

| Situation | `auto` | `tankstats` |
| --- | --- | --- |
| Short mode covers the account mode, battles match, and required account counters exist | Account counters + short tank weights, even if full was supplied | Full selected-mode counters + weights |
| Short mode is absent, battles mismatch, or required account counters are absent | Full selected-mode data, if usable | Full selected-mode data |
| Required full mode/fields are unavailable | PreparationUnavailable | PreparationUnavailable |
| Supplied counters are malformed | InvalidInput; no silent substitution of bad values | Checks the full data independently of account counters |

Full preparation sums its own counters and uses its own tank battle weights. Full battles do not have to equal account battles. It never mixes account counters with full weights. ER/WN6 average tier is battle-weighted for that same selected mode, rounded to two decimals. Catalogue metadata accepts `tier` or `level` in a tank-ID map or decoded encyclopedia/vehicles envelope.

Flat account/tanks covers `all` only. Extra random counters in account/info alone are insufficient for ER/WN6 because random average tier still needs random tank weights; they are also insufficient for WN8's tank-weighted expected totals. Complete full mode records are required. Explicit mode-keyed short records are additionally supported.

An empty full list cannot establish coverage. Under auto, zero full battles cannot replace a played account mode. An explicit tankstats request can prepare a complete zero-battle mode. A mode absent from account/info can still be prepared from complete full mode records.

## Prepared associative arguments and rating results

Preparation returns ordinary associative arrays whose keys exactly match calculator parameter names. PHP 8.5 treats these keys as named arguments during `...` unpacking; key order does not matter. There are no metadata keys in calculation arrays. Inspect the report/diagnostics for mode and source information.

### ER

```php
$er = $preparer->er('all');
echo json_encode($er);
```

Prepared response:

```json
{
  "statistics": {
    "battles": 100,
    "damage_dealt": 100000,
    "frags": 100,
    "spotted": 100,
    "capture_points": 100,
    "dropped_capture_points": 100
  },
  "averageTier": 6
}
```

```php
echo json_encode($ratings->er(...$preparer->er('all')));
```

Rating response:

```json
1177
```

### WN6

```php
echo json_encode($preparer->wn6('all'));
```

Prepared response:

```json
{
  "statistics": {
    "battles": 100,
    "wins": 50,
    "damage_dealt": 100000,
    "frags": 100,
    "spotted": 100,
    "dropped_capture_points": 100
  },
  "averageTier": 6
}
```

```php
echo json_encode($ratings->wn6(...$preparer->wn6('all')));
```

Rating response:

```json
1323
```

### WN8: one calculation path for either selected source

`wn8()` preparation returns coherent aggregate statistics and tank battle weights. The calculator's `wn8()` accepts this shape whether preparation selected short data or full data. No input/result wrapper or caller-side source branch is needed.

```php
echo json_encode($preparer->wn8('all'));
```

Prepared response:

```json
{
  "statistics": {
    "battles": 100,
    "wins": 50,
    "damage_dealt": 100000,
    "frags": 100,
    "spotted": 100,
    "dropped_capture_points": 100
  },
  "tankBattles": [
    {
      "tank_id": 1,
      "battles": 100
    }
  ],
  "expectedValues": null
}
```

Here `expectedValues: null` selects the table already installed on the calculator.

```php
echo json_encode([
    'all' => $ratings->wn8(...$preparer->wn8('all')),
    'random' => $ratings->wn8(...$preparer->wn8('random')),
    'forcedAll' => $ratings->wn8(...$preparer->wn8('all', source: 'tankstats')),
]);
```

Rating response:

```json
{"all":1565,"random":1565,"forcedAll":3091}
```

All still uses matching short data. Random uses full data. The forced all result uses the full dataset's different damage counters.

### WN8: editable full tank rows

`wn8FromFull(string $mode = 'all'): array` always prepares full rows. The returned `tanks` key matches the calculator parameter; it is not named fullTanks.

```php
echo json_encode($preparer->wn8FromFull('random'));
```

Prepared response:

```json
{
  "tanks": [
    {
      "tank_id": 1,
      "statistics": {
        "battles": 60,
        "wins": 30,
        "damage_dealt": 60000,
        "frags": 60,
        "spotted": 60,
        "dropped_capture_points": 60
      }
    }
  ],
  "expectedValues": null
}
```

```php
echo json_encode($ratings->wn8FromFull(...$preparer->wn8FromFull('random')));
```

Rating response:

```json
1565
```

Every preparation method throws `PreparationUnavailable` if usable data is absent, or `InvalidInput` for malformed data/unknown selectors. Choose a ready mode using availableModes before requesting it. Before loading any player, availableModes and diagnostics return empty arrays; requesting calculation inputs or adding extracted full records raises InvalidInput.

## Inspect and edit arguments

Each call returns an independent array. Editing it leaves original source records, the preparer's cached data and other returned arrays unchanged. Calculators revalidate edited arguments.

```php
$edited = $preparer->wn8('all');
$edited['statistics']['damage_dealt'] *= 2;
echo json_encode([
    'edited' => $ratings->wn8(...$edited),
    'original' => $ratings->wn8(...$preparer->wn8('all')),
]);
```

Response:

```json
{"edited":3091,"original":1565}
```

For ER/WN6, edit `statistics` and/or `averageTier`. For the aggregate WN8 path, keep statistics battles and tankBattles sums consistent. For full-row WN8, edit `tanks`; wn8FromFull recomputes aggregate totals from those rows. Aggregate and full-row outputs are independent and do not synchronize after edits.

```php
$fullArguments = $preparer->wn8FromFull('random');
$fullArguments['tanks'][0]['statistics']['damage_dealt'] *= 2;
echo json_encode($ratings->wn8FromFull(...$fullArguments));
```

Response:

```json
3091
```

## Battle-count mismatches and reusable loading

`battleCountMismatches(): array` returns comparable account/short totals keyed by mode. It is independent of missing mode coverage and remains observable when supplied full data makes a calculation possible. It does not compare account totals against full totals, because full preparation uses its own complete counters.

```php
$shortMismatch = $data['short'];
$shortMismatch[0]['statistics']['battles'] = 99;
$status = $preparer->setData($data['player'], $shortMismatch);
echo json_encode([
    'status' => $status,
    'mismatches' => $preparer->battleCountMismatches(),
    'missingWn8Modes' => $preparer->missingFullTankModes('wn8'),
]);
```

Response:

```json
{"status":false,"mismatches":{"all":{"accountBattles":100,"tankBattles":99}},"missingWn8Modes":["random"]}
```

`all` has a numeric mismatch; `random` lacks tank mode data. They are separate diagnoses. The new setData call also cleared the earlier full dataset.

When loading only account and short tank records, this conditional supplies full data for a detected mismatch. Missing modes are queried separately even when status is true:

```php
if ($preparer->setData($data['player'], $shortMismatch) === false) {
    $preparer->setFullTankStatistics($data['full']);
}
echo json_encode([
    'mismatches' => $preparer->battleCountMismatches(),
    'ready' => $preparer->availableModes('wn8'),
    'wn8All' => $ratings->wn8(...$preparer->wn8('all')),
]);
```

Response:

```json
{"mismatches":{"all":{"accountBattles":100,"tankBattles":99}},"ready":["all","random"],"wn8All":3091}
```

Passing full data as the third setData argument produces the same prepared results immediately. The status is still false for a detected short/account mismatch; it reports the comparison, not whether full fallback resolved preparation. Use readiness to decide whether additional collection is needed.

## Load account and full tank data together

When all three responses are already available, either loading setter can accept full statistics immediately. The following calls prepare the same data. setData takes selected records; setApiResponses takes the whole responses:

```php
$recordStatus = $apiPreparer->setData(
    $accountInfo['data'][$accountId],
    $accountTanks['data'][$accountId],
    $tankStats['data'][$accountId],
);
$responseStatus = $created->setApiResponses(
    $accountId,
    $accountInfo,
    $accountTanks,
    $tankStats,
);
echo json_encode([
    'setDataStatus' => $recordStatus,
    'setApiResponsesStatus' => $responseStatus,
    'availableWn8Modes' => $created->availableModes('wn8'),
    'samePreparedWn8' => $apiPreparer->wn8('random') === $created->wn8('random'),
]);
```

Response:

```json
{"setDataStatus":true,"setApiResponsesStatus":true,"availableWn8Modes":["all","random"],"samePreparedWn8":true}
```

The factory accepts the same optional whole tankStats response in its fourth argument. It retains the requested account ID even when fields filtering omitted account_id, as setApiResponses does.

## Expected values and direct calculation

The same preparer can load another player. Omitting the third argument clears the preceding player's full statistics, even when the new mode names are identical:

```php
$nextPlayer = $data['player'];
$nextPlayer['account_id'] = 456;
$nextShort = $data['short'];
$nextShort[0]['account_id'] = 456;
$status = $preparer->setData($nextPlayer, $nextShort);
echo json_encode([
    'status' => $status,
    'available' => $preparer->availableModes('wn8'),
    'forcedFull' => $preparer->availableModes('wn8', 'tankstats'),
]);
```

Response:

```json
{"status":true,"available":["all"],"forcedFull":[]}
```

`WotRatings::setExpectedValues(array $expectedValues): void` installs the instance default and returns no value. Validation/normalization happens once before replacing the previous table. A failed replacement leaves the installed default intact. Calls without an override reuse it.

All calculator inputs are provider-independent flat totals, not per-battle averages. Prepared arrays are optional: supply the same arguments directly to calculate arbitrary valid counters.

```php
$statistics = $data['player']['statistics']['all'];
echo json_encode([
    'er' => $ratings->er($statistics, 6),
    'wn6' => $ratings->wn6($statistics, 6),
    'wn8' => $ratings->wn8($statistics, [['tank_id' => 1, 'battles' => 100]]),
    'wn8FromFull' => $ratings->wn8FromFull([['tank_id' => 1, 'statistics' => $statistics]]),
]);
```

Response:

```json
{"er":1177,"wn6":1323,"wn8":1565,"wn8FromFull":1565}
```

| Calculator method | Required statistics fields | Additional arguments | Result |
| --- | --- | --- | --- |
| er | battles, damage_dealt, frags, spotted, capture_points, dropped_capture_points | averageTier | Rounded int |
| wn6 | battles, wins, damage_dealt, frags, spotted, dropped_capture_points | averageTier | Rounded int |
| wn8 | Same counters as wn6 | tankBattles list of tank_id/battles, optional expectedValues | Rounded int |
| wn8FromFull | Same counters as wn6 in each flat statistics row | tanks list of tank_id/statistics, optional expectedValues | Rounded int |

Battles are nonnegative integers, other required counters are finite nonnegative numbers, and wins cannot exceed battles. Tank IDs are unique positive integers or canonical numeric strings. Direct wn8 requires tank battle sums to match statistics battles. Average tier is finite and nonnegative; preparation requires a positive catalogue tier for each played tank.

Expected input accepts a decoded XVM document with data, a list of IDNum rows, or a tank-ID map. Each row needs positive finite expDamage, expSpot, expFrag, expDef and expWinRate; win rate uses percent and cannot exceed 100. Empty tables, malformed entries, duplicate IDs and conflicting IDs are rejected.

A non-null expectedValues override is validated/normalized once for that calculation and never changes the installed table. Null selects the default; an empty override fails instead of falling back. The preparer's optional constructor expectedValues attaches a normalized table to each WN8 argument array. Passing that editable table is an override and revalidates it; for repeated calculations with one unchanged table, install it on the calculator and leave preparer expectedValues null.

```php
$edited['expectedValues'] = $data['expected'];
$edited['expectedValues'][1]['expDamage'] *= 2;
echo json_encode([
    'editedWithOverride' => $ratings->wn8(...$edited),
    'installedDefault' => $ratings->wn8(...$preparer->wn8('all')),
]);
```

Response:

```json
{"editedWithOverride":1565,"installedDefault":1565}
```

Zero-battle inputs return zero but still need required fields; WN8 still needs an expected table. Eligibility policies such as a minimum number of battles belong to the caller. WN6 rounds win percentage to one decimal before calculating. Different snapshots, modes, expected tables and rounding conventions can produce different published ratings.

## Historical methods

LegacyRatings uses independent caller-supplied totals. A WN6-prepared statistics array supplies WN7's fields. Broni also requires capture_points and total xp from the same selected mode/source; add both when starting with a WN6 array. Noobmeter needs the caller's historical nominal_damage for each vehicle's battle count.

| Method | Statistics / arguments | Result |
| --- | --- | --- |
| wn7(statistics, averageTier) | WN6 fields, weighted tier | Rounded int |
| broni(statistics) | WN6 fields plus capture_points and xp | Rounded int |
| noobmeter(statistics, tankBattles, averageTier) | battles/wins/damage_dealt; list of battles/nominal_damage rows; weighted tier | Rounded int |
| xeff(rating), xwn6(rating), xwn7(rating), xnm(rating) | A finite rating | Historical 0–100 float scale |

```php
$legacy = new LegacyRatings();
$statistics = $data['player']['statistics']['all'];
echo json_encode([
    'wn7' => $legacy->wn7($statistics, 6),
    'broni' => $legacy->broni($statistics + ['xp' => 50000]),
    'noobmeter' => $legacy->noobmeter($statistics, [['battles' => 100, 'nominal_damage' => 1000]], 6),
    'xeff' => round($legacy->xeff(1000), 3),
    'xwn6' => round($legacy->xwn6(1000), 3),
    'xwn7' => round($legacy->xwn7(1000), 3),
    'xnm' => round($legacy->xnm(1000), 3),
]);
```

Response (scale values rounded for display):

```json
{"wn7":1323,"broni":1796,"noobmeter":1502,"xeff":42.954,"xwn6":43.12,"xwn7":42.7,"xnm":17.13}
```

These scales are historical formulas, not current XVM percentile conversions.

## Errors, caching and API changes

| Exception | Meaning |
| --- | --- |
| Exception\PreparationUnavailable | Required mode/counter coverage is missing or a battle mismatch has no usable full fallback |
| Exception\InvalidInput | Malformed records/counters, selectors, metadata or expected tables; inconsistent direct WN8 battle totals; non-finite counters, including full aggregate totals |
| Exception\MissingExpectedValues | No installed/supplied table, or no entry for a played tank |
| Exception\CalculationFailed | Detected arithmetic overflow or rating outside the supported integer range |

Prepared data and readiness are memoized within the current player. Auto fallback and explicit full requests share full preparation; WN6/WN8 share their counter preparation. Full replacement refreshes dependent caches. Every setData/setApiResponses starts a new player snapshot and clears the preceding full data, identity, report and caches after structural validation. Catalogue and optional expected configuration remain installed.

Memory depends on tank count, modes and requested sources. The reusable preparer releases its preceding caches when loading another player; the calculator can keep one installed expected table across the batch. Returned arrays use PHP copy-on-write and remain valid after subsequent loads.

This development API replaces the earlier player-in-constructor and DTO-returning interface. Configure the constructor, load players through setData/setApiResponses, and unpack the returned arrays. WN8 now throws PreparationUnavailable instead of returning an input/reason wrapper. missingFullTankModes requires a rating and excludes numeric mismatches, which have their own method. WgParser integration remains separate.

## Development and verification

```sh
composer install
composer test
composer analyse
composer format:check
php examples/player-ratings.php

# Download the external reference separately; runtime code has no network transport.
mkdir -p .reference
curl --fail https://static.modxvm.com/wn8-data-exp/json/wg/wn8exp.json -o .reference/wn8exp.json
php tools/check-expected-values.php .reference/wn8exp.json
```

Tests are offline and use synthetic data, including execution of the PHP README examples against their documented JSON responses on PHP 8.5. Pint uses the PSR-12 preset; Git attributes enforce LF text checkouts on Windows and Linux.

Historical golden values come from the owner's read-only rating.php; the fixture records its SHA-256. The separate expected check validates every row with synthetic ratios equal to one (WN8 1565); it does not compare live leaderboard snapshots.

Formula provenance: the owner's historical rating.php and existing WgParser calculations. Expected-table provenance: the official XVM URL above. External expected data is not bundled or relicensed. Intended consumer: the World of Tanks statistics collector.
