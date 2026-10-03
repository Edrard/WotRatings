<?php

declare(strict_types=1);

namespace Edrard\WotRatings;

use Edrard\WotRatings\Exception\CalculationFailed;
use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\Exception\PreparationUnavailable;
use Edrard\WotRatings\Internal\ExpectedValues;
use Edrard\WotRatings\Internal\Input;
use Edrard\WotRatings\Preparation\CombatRatingInput;
use Edrard\WotRatings\Preparation\Wn8Input;
use Edrard\WotRatings\Preparation\Wn8Preparation;

/** Converts one decoded player snapshot into editable selected-mode inputs, without I/O. */
final class PlayerDataPreparer
{
    private const WN8_FIELDS = ['wins', 'damage_dealt', 'frags', 'spotted', 'dropped_capture_points'];
    private const ER_FIELDS = ['damage_dealt', 'frags', 'spotted', 'capture_points', 'dropped_capture_points'];

    /** @var array<string, array<string, array{ready: bool, reason: string|null, requiresFullTanks: bool, source: string|null}>> */
    private array $availability;

    /** @var array<int, array<string, float>>|null */
    private readonly ?array $expectedValues;

    /** @var array<int|string, mixed> */
    private readonly array $catalogue;

    private readonly ?int $accountId;

    /**
     * @param array<string, mixed> $player Decoded account/info record, not its envelope.
     * @param array<int|string, mixed> $shortTanks account/tanks records
     * @param array<int|string, mixed>|null $fullTanks tanks/stats records; null means not supplied.
     * @param array<int|string, mixed> $catalogue Vehicle map or decoded encyclopedia/vehicles envelope.
     * @param array<int|string, mixed>|null $expectedValues XVM document, rows or tank-ID map.
     */
    public function __construct(private readonly array $player, private readonly array $shortTanks = [], private ?array $fullTanks = null, array $catalogue = [], ?array $expectedValues = null)
    {
        $this->accountId = isset($player['account_id']) ? Input::tankId($player['account_id']) : null;
        $this->catalogue = self::catalogueRecords($catalogue);
        $this->expectedValues = $expectedValues === null ? null : ExpectedValues::normalize($expectedValues);
        $this->availability = $this->analyzeAvailability();
    }

    /**
     * Modes ready for at least one rating, or the requested rating.
     * Readiness concerns counters; WN8 expected values may be installed on the calculator.
     * @return list<string>
     */
    public function availableModes(?string $rating = null, string $source = 'auto'): array
    {
        if ($rating !== null && !in_array($rating, ['er', 'wn6', 'wn8'], true)) {
            throw new InvalidInput('Rating must be er, wn6 or wn8.');
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

    /** @return array<string, array<string, array{ready: bool, reason: string|null, requiresFullTanks: bool, source: string|null}>> */
    public function diagnostics(string $source = 'auto'): array
    {
        $this->validateSource($source);
        return $source === 'auto' ? $this->availability : $this->analyzeAvailability($source);
    }

    /** @return list<string> Modes needing usable full tank counters for WN8; other modes remain usable. */
    public function missingFullTankModes(): array
    {
        $modes = [];
        foreach ($this->availability as $mode => $ratings) {
            if ($ratings['wn8']['requiresFullTanks']) {
                $modes[] = $mode;
            }
        }
        return $modes;
    }

    /**
     * Replace/add extracted tanks/stats records and refresh readiness. Existing inputs stay independent.
     * @param array<int|string, mixed> $fullTanks
     */
    public function setFullTankStatistics(array $fullTanks): void
    {
        $this->validateTankRecords($fullTanks);
        $this->fullTanks = $fullTanks;
        $this->availability = $this->analyzeAvailability();
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
    public static function fromApiResponses(int $accountId, array $accountInfo, array $accountTanks, ?array $tankStats = null, array $catalogue = [], ?array $expectedValues = null): self
    {
        Input::tankId($accountId);
        $player = self::responseRecord($accountInfo, $accountId);
        $short = self::responseRecord($accountTanks, $accountId);
        $full = $tankStats === null ? null : self::responseRecord($tankStats, $accountId);
        if (!is_array($player) || !is_array($short) || ($full !== null && !is_array($full))) {
            throw new InvalidInput('API responses must contain an existing player and tank arrays.');
        }
        if (isset($player['account_id']) && $player['account_id'] !== $accountId) {
            throw new InvalidInput('Player account_id does not match the requested ID.');
        }
        // Preserve identity even when an account/info fields filter omits account_id.
        $player['account_id'] = $accountId;
        return new self($player, $short, $full, $catalogue, $expectedValues);
    }

    public function er(string $mode = 'all', string $source = 'auto'): CombatRatingInput
    {
        $data = $this->selectedData($mode, $source, self::ER_FIELDS);
        return new CombatRatingInput($data['statistics'], $this->averageTier($data['vehicles']), $mode, $data['source']);
    }

    public function wn6(string $mode = 'all', string $source = 'auto'): CombatRatingInput
    {
        $data = $this->selectedData($mode, $source, self::WN8_FIELDS);
        return new CombatRatingInput($data['statistics'], $this->averageTier($data['vehicles']), $mode, $data['source']);
    }

    public function wn8(string $mode = 'all', string $source = 'auto'): Wn8Preparation
    {
        try {
            $data = $this->selectedData($mode, $source, self::WN8_FIELDS);
        } catch (PreparationUnavailable $failure) {
            return Wn8Preparation::unavailable($failure->getMessage());
        }
        $battles = array_map(static fn (array $tank): array => ['tank_id' => $tank['tank_id'], 'battles' => $tank['statistics']['battles']], $data['vehicles']);
        return Wn8Preparation::ready(new Wn8Input(
            $data['statistics'],
            $battles,
            $data['source'] === 'full' ? $data['vehicles'] : [],
            $data['source'],
            $mode,
            $this->expectedValues
        ));
    }

    public function wn8FromFull(string $mode = 'all'): Wn8Preparation
    {
        return $this->wn8($mode, 'tankstats');
    }

    /** @return array<string, array<string, array{ready: bool, reason: string|null, requiresFullTanks: bool, source: string|null}>> */
    private function analyzeAvailability(string $source = 'auto'): array
    {
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
                $status = ['ready' => false, 'reason' => null, 'requiresFullTanks' => false, 'source' => null];
                try {
                    $data = $this->selectedData($mode, $source, $rating === 'er' ? self::ER_FIELDS : self::WN8_FIELDS);
                    if ($rating !== 'wn8') {
                        $this->averageTier($data['vehicles']);
                    }
                    $status['ready'] = true;
                    $status['source'] = $data['source'];
                } catch (PreparationUnavailable $failure) {
                    $status['reason'] = $failure->getMessage();
                    $status['requiresFullTanks'] = true;
                } catch (InvalidInput | CalculationFailed $failure) {
                    $status['reason'] = $failure->getMessage();
                }
                $availability[$mode][$rating] = $status;
            }
        }
        return $availability;
    }

    /**
     * Select one coherent source for both counters and vehicle battle weights.
     * @param list<string> $fields
     * @return array{statistics: array<string, int|float>, vehicles: list<array{tank_id: int, statistics: array<string, int|float>}>, source: string}
     */
    private function selectedData(string $mode, string $source, array $fields): array
    {
        $this->validateSource($source);
        $account = $this->player['statistics'][$mode] ?? null;
        if ($mode === '' || ($source === 'auto' && !is_array($account) && $this->fullTanks === null)) {
            throw new InvalidInput("Player statistics require mode $mode.");
        }
        $accountBattles = $source === 'auto' && is_array($account) ? Input::battles($account['battles'] ?? null) : null;
        $short = $accountBattles !== null ? $this->vehicles($this->shortTanks, $mode, false) : null;
        if (is_array($account) && $short !== null && $this->sumBattles($short) === $accountBattles) {
            return ['statistics' => $this->counters($account, $fields), 'vehicles' => $short, 'source' => 'short'];
        }
        if ($this->fullTanks === null) {
            throw new PreparationUnavailable($source === 'tankstats'
                ? 'Full tank statistics were requested but not supplied.'
                : 'Short tank totals do not match or cover this mode; full tank statistics were not supplied.');
        }
        $full = $this->vehicles($this->fullTanks, $mode, true, $fields);
        if ($full === null || $full === [] || ($this->sumBattles($full) === 0 && $accountBattles !== null && $accountBattles > 0)) {
            throw new PreparationUnavailable('Full tank statistics have no usable battles or mode coverage for this mode.');
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
            $stats[$field] = Input::counter($raw, $field);
        }
        if (isset($stats['wins']) && $stats['wins'] > $stats['battles']) {
            throw new InvalidInput('Wins cannot exceed battles.');
        }
        return $stats;
    }

    /** @param array<int|string, mixed> $rows */
    private function validateTankRecords(array $rows): void
    {
        if (!array_is_list($rows)) {
            throw new InvalidInput('Tank records must be a list.');
        }
        $seen = [];
        $accountId = $this->accountId;
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
        $this->validateTankRecords($rows);
        $result = [];
        foreach ($rows as $tank) {
            // Flat short statistics describe all only. Never reuse them for another mode.
            $raw = $full ? ($tank[$mode] ?? null) : ($tank['statistics'][$mode] ?? ($mode === 'all' ? ($tank['statistics'] ?? null) : null));
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
    private function averageTier(array $rows): float
    {
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
        return $sum > 0 ? round($weighted / $sum, 2) : 0.0;
    }
}
