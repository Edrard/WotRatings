<?php

declare(strict_types=1);

namespace Edrard\WotRatings;

use Edrard\WotRatings\Exception\CalculationFailed;
use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\Exception\PreparationUnavailable;
use Edrard\WotRatings\Internal\ExpectedValues;
use Edrard\WotRatings\Internal\Input;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reusable offline preparer. Each setData() replaces the current player snapshot.
 * @phpstan-type Vehicle array{tank_id: int, statistics: array<string, int|float>}
 * @phpstan-type SelectedData array{statistics: array<string, int|float>, vehicles: list<Vehicle>, source: string, tankBattles: list<array{tank_id: int, battles: int}>}
 * @phpstan-type PreparationFailure array{class: class-string<InvalidInput|CalculationFailed|PreparationUnavailable>, message: string, code: int}
 * @phpstan-type Availability array<string, array<string, array{ready: bool, reason: string|null, issue: string|null, requiresFullTanks: bool, source: string|null}>>
 * @phpstan-type BattleMismatches array<string, array{accountBattles: int, tankBattles: int}>
 * @phpstan-type Report array{battleCountMismatches: BattleMismatches, missingFullTankModes: array<string, list<string>>, diagnostics: Availability}
 */
final class PlayerDataPreparer
{
    private const WN8_FIELDS = ['wins', 'damage_dealt', 'frags', 'spotted', 'dropped_capture_points'];
    private const ER_FIELDS = ['damage_dealt', 'frags', 'spotted', 'capture_points', 'dropped_capture_points'];

    /** @var Availability */
    private array $availability = [];

    /** @var Report Read-only externally; assign a copy locally to inspect or annotate it. */
    public private(set) array $report = ['battleCountMismatches' => [], 'missingFullTankModes' => [], 'diagnostics' => []];

    /** @var array<string, mixed>|null */
    private ?array $player = null;

    /** @var array<int|string, mixed> */
    private array $shortTanks = [];

    /** @var array<int|string, mixed>|null */
    private ?array $fullTanks = null;

    /** @var BattleMismatches */
    private array $battleMismatches = [];

    /** @var array<string, list<Vehicle>|PreparationFailure|null> */
    private array $shortModes = [];

    /** @var Availability|null */
    private ?array $fullAvailability = null;

    /** @var array<string, SelectedData|PreparationFailure> Internal arrays only; never shared input objects. */
    private array $preparedData = [];

    /** @var array<string, array<string, float>> Weighted tier depends on mode and actual source, not rating. */
    private array $averageTiers = [];

    /** @var array<int, array<string, float>>|null */
    private readonly ?array $expectedValues;

    /** @var array<int|string, mixed> */
    private readonly array $catalogue;

    private ?int $accountId = null;
    private readonly LoggerInterface $logger;

    /**
     * @param array<int|string, mixed> $catalogue Vehicle map or decoded encyclopedia/vehicles envelope.
     * @param array<int|string, mixed>|null $expectedValues XVM document, rows or tank-ID map.
     */
    public function __construct(array $catalogue = [], ?array $expectedValues = null, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->catalogue = self::catalogueRecords($catalogue);
        $this->expectedValues = $expectedValues === null ? null : ExpectedValues::normalize($expectedValues);
    }

    /**
     * Replace the player and check comparable account/short tank battle counts immediately.
     * False means a battle-count mismatch was detected, even if full data resolves preparation.
     * True means no mismatch was detected; it does not certify mode coverage or field validity.
     * @param array<string, mixed> $player Extracted account/info record.
     * @param array<int|string, mixed> $shortTanks Extracted account/tanks list.
     * @param array<int|string, mixed>|null $fullTanks Extracted tanks/stats list.
     */
    public function setData(array $player, array $shortTanks, ?array $fullTanks = null): bool
    {
        if (!is_array($player['statistics'] ?? null)) {
            throw new InvalidInput('Player requires a statistics mode map.');
        }
        $accountId = isset($player['account_id']) ? Input::tankId($player['account_id']) : null;
        $this->validateTankRecords($shortTanks, $accountId);
        if ($fullTanks !== null) {
            $this->validateTankRecords($fullTanks, $accountId);
        }
        $this->player = $player;
        $this->shortTanks = $shortTanks;
        $this->fullTanks = $fullTanks;
        $this->accountId = $accountId;
        $this->shortModes = [];
        $this->clearPreparation();
        $this->battleMismatches = $this->analyzeBattleCounts();
        $this->refreshReport();
        return $this->battleMismatches === [];
    }

    /**
     * Decoded WG envelopes; same status contract as setData(). No requests are performed.
     * @param array<string, mixed> $accountInfo
     * @param array<string, mixed> $accountTanks
     * @param array<string, mixed>|null $tankStats
     */
    public function setApiResponses(int $accountId, array $accountInfo, array $accountTanks, ?array $tankStats = null): bool
    {
        Input::tankId($accountId);
        $player = self::responseRecord($accountInfo, $accountId);
        $short = self::responseRecord($accountTanks, $accountId);
        $full = $tankStats === null ? null : self::responseRecord($tankStats, $accountId);
        if (!is_array($player) || !is_array($short) || ($tankStats !== null && !is_array($full))) {
            throw new InvalidInput('API responses must contain an existing player and tank arrays.');
        }
        if (isset($player['account_id']) && $player['account_id'] !== $accountId) {
            throw new InvalidInput('Player account_id does not match the requested ID.');
        }
        $player['account_id'] = $accountId;
        return $this->setData($player, $short, $full);
    }

    /**
     * Modes ready for at least one rating, or the requested rating.
     * Readiness concerns counters; WN8 expected values may be installed on the calculator.
     * @return list<string>
     */
    public function availableModes(?string $rating = null, string $source = 'auto'): array
    {
        if ($rating !== null) {
            $this->validateRating($rating);
        }
        $modes = [];
        foreach ($this->diagnostics($source) as $mode => $ratings) {
            $ready = $rating === null
                ? in_array(true, array_column($ratings, 'ready'), true)
                : $ratings[$rating]['ready'];
            if ($ready) {
                $modes[] = $mode;
            }
        }
        return $modes;
    }

    /** @return Availability */
    public function diagnostics(string $source = 'auto'): array
    {
        $this->validateSource($source);
        return $source === 'auto' ? $this->availability : ($this->fullAvailability ??= $this->analyzeAvailability($source));
    }

    /** @return list<string> Missing mode/field coverage for this rating; mismatches are separate. */
    public function missingFullTankModes(string $rating): array
    {
        $this->validateRating($rating);
        $modes = [];
        foreach ($this->availability as $mode => $ratings) {
            if ($ratings[$rating]['issue'] === 'missing_mode_data') {
                $modes[] = $mode;
            }
        }
        return $modes;
    }

    /** @return BattleMismatches Comparable short counts only; full totals are independent. */
    public function battleCountMismatches(): array
    {
        return $this->battleMismatches;
    }

    /**
     * Replace/add extracted tanks/stats records and refresh readiness. Existing inputs stay independent.
     * @param array<int|string, mixed> $fullTanks
     */
    public function setFullTankStatistics(array $fullTanks): void
    {
        $this->requirePlayer();
        $this->validateTankRecords($fullTanks, $this->accountId);
        $this->fullTanks = $fullTanks;
        $this->clearPreparation();
        $this->refreshReport();
    }

    /** @param array<string, mixed> $tankStats Decoded WG status/data response for the same player. */
    public function setFullTankStatsResponse(array $tankStats): void
    {
        if ($this->accountId === null) {
            throw new InvalidInput('An account_id is required to extract a later tanks/stats response.');
        }
        $records = self::responseRecord($tankStats, $this->accountId);
        if (!is_array($records)) {
            throw new InvalidInput('The tanks/stats response must contain tank records.');
        }
        $this->setFullTankStatistics($records);
    }

    /**
     * Extract one player from decoded WG status/data envelopes. Never performs requests.
     * @param array<string, mixed> $accountInfo
     * @param array<string, mixed> $accountTanks
     * @param array<string, mixed>|null $tankStats
     * @param array<int|string, mixed> $catalogue
     * @param array<int|string, mixed>|null $expectedValues
     */
    public static function fromApiResponses(int $accountId, array $accountInfo, array $accountTanks, ?array $tankStats = null, array $catalogue = [], ?array $expectedValues = null, ?LoggerInterface $logger = null): self
    {
        $preparer = new self($catalogue, $expectedValues, $logger);
        $preparer->setApiResponses($accountId, $accountInfo, $accountTanks, $tankStats);
        return $preparer;
    }

    /** @return array{statistics: array<string, int|float>, averageTier: float} Named calculator arguments. */
    public function er(string $mode = 'all', string $source = 'auto'): array
    {
        $data = $this->selectedData($mode, $source, self::ER_FIELDS);
        return ['statistics' => $data['statistics'], 'averageTier' => $this->averageTier($data['vehicles'], $mode, $data['source'])];
    }

    /** @return array{statistics: array<string, int|float>, averageTier: float} */
    public function wn6(string $mode = 'all', string $source = 'auto'): array
    {
        $data = $this->selectedData($mode, $source, self::WN8_FIELDS);
        return ['statistics' => $data['statistics'], 'averageTier' => $this->averageTier($data['vehicles'], $mode, $data['source'])];
    }

    /** @return array{statistics: array<string, int|float>, tankBattles: list<array{tank_id: int, battles: int}>, expectedValues: array<int, array<string, float>>|null} */
    public function wn8(string $mode = 'all', string $source = 'auto'): array
    {
        $data = $this->selectedData($mode, $source, self::WN8_FIELDS);
        return ['statistics' => $data['statistics'], 'tankBattles' => $data['tankBattles'], 'expectedValues' => $this->expectedValues];
    }

    /** @return array{tanks: list<Vehicle>, expectedValues: array<int, array<string, float>>|null} */
    public function wn8FromFull(string $mode = 'all'): array
    {
        $data = $this->selectedData($mode, 'tankstats', self::WN8_FIELDS);
        return ['tanks' => $data['vehicles'], 'expectedValues' => $this->expectedValues];
    }

    /** @return Availability */
    private function analyzeAvailability(string $source = 'auto'): array
    {
        if ($this->player === null) {
            return [];
        }
        $statistics = $this->player['statistics'] ?? null;
        if (!is_array($statistics)) {
            throw new InvalidInput('Player requires a statistics mode map.');
        }
        $modes = [];
        foreach ($statistics as $mode => $raw) {
            // WG also supplies ancillary scalars and null optional modes.
            if (!is_string($mode) || !is_array($raw) || !array_key_exists('battles', $raw)) {
                continue;
            }
            $modes[$mode] = true;
        }
        // Detailed mode counters are sufficient even when account/info omitted an optional mode.
        foreach ($this->fullTanks ?? [] as $tank) {
            if (!is_array($tank)) {
                continue;
            }
            foreach ($tank as $mode => $raw) {
                if (is_string($mode) && is_array($raw) && array_key_exists('battles', $raw)) {
                    $modes[$mode] = true;
                }
            }
        }
        $availability = [];
        foreach (array_keys($modes) as $mode) {
            foreach (['er', 'wn6', 'wn8'] as $rating) {
                $status = ['ready' => false, 'reason' => null, 'issue' => null, 'requiresFullTanks' => false, 'source' => null];
                try {
                    $data = $this->selectedData($mode, $source, $rating === 'er' ? self::ER_FIELDS : self::WN8_FIELDS);
                    if ($rating !== 'wn8') {
                        $this->averageTier($data['vehicles'], $mode, $data['source']);
                    }
                    $status['ready'] = true;
                    $status['source'] = $data['source'];
                } catch (PreparationUnavailable $failure) {
                    $status['reason'] = $failure->getMessage();
                    $status['issue'] = $failure->getCode() === PreparationUnavailable::BATTLE_COUNT_MISMATCH ? 'battle_count_mismatch' : 'missing_mode_data';
                    $status['requiresFullTanks'] = true;
                } catch (InvalidInput | CalculationFailed $failure) {
                    $status['reason'] = $failure->getMessage();
                    $status['issue'] = $failure instanceof InvalidInput ? 'invalid_input' : 'calculation_failed';
                }
                $availability[$mode][$rating] = $status;
            }
        }
        return $availability;
    }

    private function requirePlayer(): void
    {
        if ($this->player === null) {
            throw new InvalidInput('Call setData() or setApiResponses() before requesting preparation.');
        }
    }

    private function clearPreparation(): void
    {
        $this->preparedData = [];
        $this->averageTiers = [];
        $this->fullAvailability = null;
    }

    private function refreshReport(): void
    {
        $this->availability = $this->analyzeAvailability();
        $missing = [];
        foreach (['er', 'wn6', 'wn8'] as $rating) {
            $missing[$rating] = $this->missingFullTankModes($rating);
        }
        $this->report = ['battleCountMismatches' => $this->battleMismatches, 'missingFullTankModes' => $missing, 'diagnostics' => $this->availability];
        $this->logReadiness();
    }

    private function logReadiness(): void
    {
        if ($this->logger instanceof NullLogger) {
            return;
        }
        try {
            $unavailable = $invalid = 0;
            foreach ($this->availability as $ratings) {
                foreach ($ratings as $status) {
                    if (!$status['ready']) {
                        ++$unavailable;
                        $invalid += in_array($status['issue'], ['invalid_input', 'calculation_failed'], true) ? 1 : 0;
                    }
                }
            }
            if ($unavailable === 0 && $this->battleMismatches === []) {
                return;
            }
            $this->logger->log(
                $invalid > 0 || $this->battleMismatches !== [] ? 'warning' : 'debug',
                'WotRatings preparation found {mismatchModes} mismatched modes and {unavailableRatings} unavailable rating/mode pairs.',
                ['mismatchModes' => count($this->battleMismatches), 'unavailableRatings' => $unavailable, 'invalidRatings' => $invalid]
            );
        } catch (Throwable) {
            // Preparation and its report remain usable when diagnostic delivery fails.
        }
    }

    /** @return BattleMismatches */
    private function analyzeBattleCounts(): array
    {
        $mismatches = [];
        $statistics = $this->player['statistics'] ?? [];
        foreach ($statistics as $mode => $raw) {
            if (!is_string($mode) || !is_array($raw) || !array_key_exists('battles', $raw)) {
                continue;
            }
            try {
                $account = Input::battles($raw['battles']);
                $short = $this->shortVehicles($mode);
                if ($short === null || ($short === [] && $account > 0)) {
                    continue;
                }
                $tanks = $this->sumBattles($short);
                if ($account !== $tanks) {
                    $mismatches[$mode] = ['accountBattles' => $account, 'tankBattles' => $tanks];
                }
            } catch (InvalidInput | CalculationFailed) {
                // Unusable counters are diagnosed as errors, never invented numeric mismatches.
                continue;
            }
        }
        return $mismatches;
    }

    /** @return list<Vehicle>|null */
    private function shortVehicles(string $mode): ?array
    {
        if (!array_key_exists($mode, $this->shortModes)) {
            try {
                $this->shortModes[$mode] = $this->vehicles($this->shortTanks, $mode, false);
            } catch (InvalidInput | CalculationFailed $failure) {
                $this->shortModes[$mode] = ['class' => $failure::class, 'message' => $failure->getMessage(), 'code' => $failure->getCode()];
            }
        }
        $cached = $this->shortModes[$mode];
        if (isset($cached['class'])) {
            $class = $cached['class'];
            throw new $class($cached['message'], $cached['code']);
        }
        return $cached;
    }

    /**
     * Select one coherent source for both counters and vehicle battle weights.
     * @param list<string> $fields
     * @return SelectedData
     */
    private function selectedData(string $mode, string $source, array $fields): array
    {
        $this->requirePlayer();
        $this->validateSource($source);
        $key = serialize([$source, $mode, $fields]);
        if (!isset($this->preparedData[$key])) {
            try {
                $data = $this->selectData($mode, $source, $fields);
                $data['tankBattles'] ??= array_map(static fn (array $tank): array => ['tank_id' => $tank['tank_id'], 'battles' => $tank['statistics']['battles']], $data['vehicles']);
                $this->preparedData[$key] = $data;
            } catch (InvalidInput | CalculationFailed | PreparationUnavailable $failure) {
                // Store failure metadata rather than retaining exception traces and their inputs.
                $this->preparedData[$key] = ['class' => $failure::class, 'message' => $failure->getMessage(), 'code' => $failure->getCode()];
            }
        }
        $cached = $this->preparedData[$key];
        if (isset($cached['class'])) {
            $class = $cached['class'];
            throw new $class($cached['message'], $cached['code']);
        }
        return $cached;
    }

    /**
     * @param list<string> $fields
     * @return array{statistics: array<string, int|float>, vehicles: list<array{tank_id: int, statistics: array<string, int|float>}>, source: string, tankBattles?: list<array{tank_id: int, battles: int}>}
     */
    private function selectData(string $mode, string $source, array $fields): array
    {
        $this->validateSource($source);
        $account = $this->player['statistics'][$mode] ?? null;
        if ($mode === '' || ($source === 'auto' && !is_array($account) && $this->fullTanks === null)) {
            throw new InvalidInput("Player statistics require mode $mode.");
        }
        $accountBattles = $source === 'auto' && is_array($account) ? Input::battles($account['battles'] ?? null) : null;
        $short = $accountBattles !== null ? $this->shortVehicles($mode) : null;
        if (is_array($account) && $short !== null && $this->sumBattles($short) === $accountBattles) {
            try {
                return ['statistics' => $this->counters($account, $fields), 'vehicles' => $short, 'source' => 'short'];
            } catch (PreparationUnavailable) {
                // Missing account fields may be supplied by complete full-mode rows.
            }
        }
        if ($this->fullTanks === null) {
            if ($source === 'auto' && isset($this->battleMismatches[$mode])) {
                throw new PreparationUnavailable("Short tank battle counts differ from account mode $mode; full statistics were not supplied.", PreparationUnavailable::BATTLE_COUNT_MISMATCH);
            }
            throw new PreparationUnavailable($source === 'tankstats'
                ? 'Full tank statistics were requested but not supplied.'
                : "Mode $mode lacks short coverage or required counters; full statistics were not supplied.", PreparationUnavailable::MISSING_MODE_DATA);
        }
        if ($source === 'auto') {
            // Auto fallback and explicit full requests share the same full-mode preparation.
            try {
                $data = $this->selectedData($mode, 'tankstats', $fields);
            } catch (PreparationUnavailable $failure) {
                if (isset($this->battleMismatches[$mode])) {
                    throw new PreparationUnavailable("Short tank battle counts differ from account mode $mode; ".$failure->getMessage(), PreparationUnavailable::BATTLE_COUNT_MISMATCH);
                }
                throw $failure;
            }
            if ($data['statistics']['battles'] === 0 && $accountBattles !== null && $accountBattles > 0) {
                throw new PreparationUnavailable('Full tank statistics have no usable battles for this played mode.', isset($this->battleMismatches[$mode]) ? PreparationUnavailable::BATTLE_COUNT_MISMATCH : PreparationUnavailable::MISSING_MODE_DATA);
            }
            return $data;
        }
        $full = $this->vehicles($this->fullTanks, $mode, true, $fields);
        if ($full === null || $full === []) {
            throw new PreparationUnavailable('Full tank statistics have no usable battles or mode coverage for this mode.', PreparationUnavailable::MISSING_MODE_DATA);
        }
        $totals = array_fill_keys(['battles', ...$fields], 0);
        $totals['battles'] = $this->sumBattles($full);
        foreach ($full as $tank) {
            foreach ($fields as $field) {
                $totals[$field] += $tank['statistics'][$field];
                Input::finite((float) $totals[$field]);
            }
        }
        return ['statistics' => $totals, 'vehicles' => $full, 'source' => 'full'];
    }

    private function validateSource(string $source): void
    {
        if (!in_array($source, ['auto', 'tankstats'], true)) {
            throw new InvalidInput('Source must be auto or tankstats.');
        }
    }

    private function validateRating(string $rating): void
    {
        if (!in_array($rating, ['er', 'wn6', 'wn8'], true)) {
            throw new InvalidInput('Rating must be er, wn6 or wn8.');
        }
    }

    /** @param array<string, mixed> $response */
    private static function responseRecord(array $response, int $id): mixed
    {
        if (($response['status'] ?? null) !== 'ok' || !is_array($response['data'] ?? null) || !array_key_exists($id, $response['data'])) {
            throw new InvalidInput('API response requires status ok and requested data coverage.');
        }
        return $response['data'][$id];
    }

    /**
     * @param array<int|string, mixed> $catalogue
     * @return array<int|string, mixed>
     */
    private static function catalogueRecords(array $catalogue): array
    {
        if (array_key_exists('status', $catalogue)) {
            if ($catalogue['status'] !== 'ok' || !is_array($catalogue['data'] ?? null)) {
                throw new InvalidInput('Vehicle catalogue response requires status ok and a data map.');
            }
            return $catalogue['data'];
        }
        return $catalogue;
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string> $fields
     * @return array<string, int|float>
     */
    private function counters(array $raw, array $fields): array
    {
        $stats = ['battles' => Input::battles($raw['battles'] ?? null)];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $raw) || $raw[$field] === null) {
                throw new PreparationUnavailable("Required counter $field is missing for this mode.", PreparationUnavailable::MISSING_MODE_DATA);
            }
            $stats[$field] = Input::counter($raw, $field);
        }
        if (isset($stats['wins']) && $stats['wins'] > $stats['battles']) {
            throw new InvalidInput('Wins cannot exceed battles.');
        }
        return $stats;
    }

    /** @param array<int|string, mixed> $rows */
    private function validateTankRecords(array $rows, ?int $accountId = null): void
    {
        if (!array_is_list($rows)) {
            throw new InvalidInput('Tank records must be a list.');
        }
        $seen = [];
        foreach ($rows as $tank) {
            if (!is_array($tank)) {
                throw new InvalidInput('Tank records must be arrays.');
            }
            $id = Input::tankId($tank['tank_id'] ?? null);
            if (isset($seen[$id])) {
                throw new InvalidInput('Tank IDs must be unique.');
            }
            $seen[$id] = true;
            if (isset($tank['account_id'])) {
                $owner = Input::tankId($tank['account_id']);
                $accountId ??= $owner;
                if ($owner !== $accountId) {
                    throw new InvalidInput('Tank account_id does not match this player.');
                }
            }
        }
    }

    /**
     * @param array<int|string, mixed> $rows
     * @param list<string> $fields
     * @return list<array{tank_id: int, statistics: array<string, int|float>}>|null
     */
    private function vehicles(array $rows, string $mode, bool $full, array $fields = []): ?array
    {
        $this->validateTankRecords($rows, $this->accountId);
        $result = [];
        foreach ($rows as $tank) {
            // Flat short statistics describe all only. Never reuse them for another mode.
            if ($full) {
                $raw = $tank[$mode] ?? null;
            } else {
                $statistics = $tank['statistics'] ?? null;
                $raw = is_array($statistics) ? ($statistics[$mode] ?? null) : null;
                if ($raw === null && $mode === 'all' && is_array($statistics) && !array_key_exists('all', $statistics)) {
                    $modeMap = false;
                    foreach ($statistics as $value) {
                        if (is_array($value) && array_key_exists('battles', $value)) {
                            $modeMap = true;
                            break;
                        }
                    }
                    // An absent all entry in a mode map needs full data; malformed flat counters still fail validation.
                    $raw = $modeMap && !array_key_exists('battles', $statistics) ? null : $statistics;
                }
            }
            if (!is_array($raw)) {
                return null;
            }
            $result[] = ['tank_id' => Input::tankId($tank['tank_id']), 'statistics' => $this->counters($raw, $fields)];
        }
        return !$full && $mode !== 'all' && $rows === [] ? null : $result;
    }

    /** @param list<array{tank_id: int, statistics: array<string, int|float>}> $rows */
    private function sumBattles(array $rows): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $battles = Input::battles($row['statistics']['battles']);
            if ($battles > PHP_INT_MAX - $sum) {
                throw new CalculationFailed('Battle count overflow.');
            }
            $sum += $battles;
        }
        return $sum;
    }

    /** @param list<array{tank_id: int, statistics: array<string, int|float>}> $rows */
    private function averageTier(array $rows, string $mode, string $source): float
    {
        if (isset($this->averageTiers[$source][$mode])) {
            return $this->averageTiers[$source][$mode];
        }
        $sum = $this->sumBattles($rows);
        $weighted = 0.0;
        foreach ($rows as $tank) {
            $battles = $tank['statistics']['battles'];
            if ($battles === 0) {
                continue;
            }
            $meta = $this->catalogue[$tank['tank_id']] ?? null;
            $tier = is_array($meta) ? ($meta['tier'] ?? $meta['level'] ?? null) : null;
            $tier = Input::number($tier, 'tank tier');
            if ($tier <= 0) {
                throw new InvalidInput('Tank tier must be positive.');
            }
            $weighted += $tier * $battles;
            Input::finite($weighted);
        }
        return $this->averageTiers[$source][$mode] = $sum > 0 ? round($weighted / $sum, 2) : 0.0;
    }
}
