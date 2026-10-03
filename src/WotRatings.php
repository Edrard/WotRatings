<?php

declare(strict_types=1);

namespace Edrard\WotRatings;

use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\Exception\MissingExpectedValues;
use Edrard\WotRatings\Internal\ExpectedValues;
use Edrard\WotRatings\Internal\Input;

/** Pure offline rating calculations; collection policy belongs to the caller. */
final class WotRatings
{
    /** @var array<int, array<string, float>>|null */
    private ?array $expectedValues = null;

    /** @param array<int|string, mixed> $expectedValues */
    public function setExpectedValues(array $expectedValues): void
    {
        // Validate before replacing the existing table.
        $this->expectedValues = ExpectedValues::normalize($expectedValues);
    }

    /** @param array<int|string, mixed>|null $expectedValues */
    private function assertExpectedValuesAvailable(?array $expectedValues = null): void
    {
        if ($expectedValues !== null) {
            ExpectedValues::normalize($expectedValues);
        } elseif ($this->expectedValues === null) {
            throw new MissingExpectedValues('Install expected values or pass a table to wn8().');
        }
    }

    /** @param array<string, mixed> $statistics Flat totals, not per-battle averages. */
    public function er(array $statistics, float $averageTier): int
    {
        $tier = Input::number($averageTier, 'averageTier');
        $n = Input::battles($statistics['battles'] ?? null);
        $damage = Input::counter($statistics, 'damage_dealt');
        $frags = Input::counter($statistics, 'frags');
        $spotted = Input::counter($statistics, 'spotted');
        $capture = Input::counter($statistics, 'capture_points');
        $defence = Input::counter($statistics, 'dropped_capture_points');
        if ($n === 0 || $tier === 0.0) {
            return 0;
        }
        return Input::rounded($damage / $n * (10 / ($tier + 2)) * (0.23 + 2 * $tier / 100)
            + $frags / $n * 250 + $spotted / $n * 150
            + log($capture / $n + 1, 1.732) * 150 + $defence / $n * 150);
    }

    /** @param array<string, mixed> $statistics */
    public function wn6(array $statistics, float $averageTier): int
    {
        $tier = Input::number($averageTier, 'averageTier');
        $n = Input::battles($statistics['battles'] ?? null);
        $wins = Input::number($statistics['wins'] ?? null, 'wins', maximum: $n);
        $damage = Input::counter($statistics, 'damage_dealt');
        $frags = Input::counter($statistics, 'frags');
        $spotted = Input::counter($statistics, 'spotted');
        $defence = Input::counter($statistics, 'dropped_capture_points');
        if ($n === 0 || $tier === 0.0) {
            return 0;
        }
        $win = round($wins * 100 / $n, 1);
        return Input::rounded($frags / $n * (1240 - 1040 / pow(min($tier, 6), 0.164))
            + $damage / $n * 530 / (184 * exp(0.24 * $tier) + 130)
            + $spotted / $n * 125 + min($defence / $n, 2.2) * 100
            + (185 / (0.17 + exp(($win - 35) * -0.134)) - 500) * 0.45 + (6 - min($tier, 6)) * -60);
    }

    /**
     * @param array<string, mixed> $statistics battles, wins, damage_dealt, frags, spotted, dropped_capture_points
     * @param array<int|string, mixed> $tankBattles List of tank_id and battles records, validated at the boundary.
     * @param array<int|string, mixed>|null $expectedValues Override for this call only; null selects the installed table.
     */
    public function wn8(array $statistics, array $tankBattles, ?array $expectedValues = null): int
    {
        $table = $expectedValues === null ? $this->expectedValues : ExpectedValues::normalize($expectedValues);
        if ($table === null) {
            throw new MissingExpectedValues('Install expected values or pass a table to wn8().');
        }
        $n = Input::battles($statistics['battles'] ?? null);
        $actual = [];
        foreach (['damage_dealt', 'frags', 'spotted', 'dropped_capture_points', 'wins'] as $field) {
            $actual[$field] = Input::counter($statistics, $field);
        }
        if ($actual['wins'] > $n || !array_is_list($tankBattles)) {
            throw new InvalidInput('Wins cannot exceed battles; tankBattles must be a list.');
        }
        $expected = array_fill_keys(ExpectedValues::FIELDS, 0.0);
        $seen = [];
        $sum = 0;
        foreach ($tankBattles as $tank) {
            if (!is_array($tank)) {
                throw new InvalidInput('Tank battle records must be arrays.');
            }
            $id = Input::tankId($tank['tank_id'] ?? null);
            $battles = Input::battles($tank['battles'] ?? null);
            if (isset($seen[$id]) || $battles > $n - $sum) {
                throw new InvalidInput('Tank IDs must be unique and tank battles must match statistics battles.');
            }
            $seen[$id] = true;
            $sum += $battles;
            if ($battles === 0) {
                continue;
            }
            if (!isset($table[$id])) {
                throw new MissingExpectedValues("Missing expected values for tank $id.");
            }
            foreach (ExpectedValues::FIELDS as $field) {
                $expected[$field] += $table[$id][$field] * $battles;
            }
        }
        if ($sum !== $n) {
            throw new InvalidInput('Tank battles must match statistics battles.');
        }
        if ($n === 0) {
            return 0;
        }
        foreach ($expected as $value) {
            Input::finite($value);
        }
        $damage = max(0, ($actual['damage_dealt'] / $expected['expDamage'] - 0.22) / 0.78);
        $frag = min($damage + 0.2, max(0, ($actual['frags'] / $expected['expFrag'] - 0.12) / 0.88));
        $spot = min($damage + 0.1, max(0, ($actual['spotted'] / $expected['expSpot'] - 0.38) / 0.62));
        $def = min($damage + 0.1, max(0, ($actual['dropped_capture_points'] / $expected['expDef'] - 0.10) / 0.90));
        $win = max(0, ($actual['wins'] * 100 / $expected['expWinRate'] - 0.71) / 0.29);
        return Input::rounded(980 * $damage + 210 * $damage * $frag + 155 * $frag * $spot
            + 75 * $def * $frag + 145 * min(1.8, $win));
    }

    /**
     * Full selected-mode vehicle counters; no account totals are used.
     * @param array<int|string, mixed> $tanks List of tank_id and flat statistics records.
     * @param array<int|string, mixed>|null $expectedValues
     */
    public function wn8FromFull(array $tanks, ?array $expectedValues = null): int
    {
        $this->assertExpectedValuesAvailable($expectedValues);
        if (!array_is_list($tanks)) {
            throw new InvalidInput('Full tanks must be a list.');
        }
        $statistics = array_fill_keys(['battles', 'wins', 'damage_dealt', 'frags', 'spotted', 'dropped_capture_points'], 0);
        $tankBattles = [];
        foreach ($tanks as $tank) {
            if (!is_array($tank) || !is_array($tank['statistics'] ?? null)) {
                throw new InvalidInput('Full tank records require tank_id and statistics.');
            }
            $stats = $tank['statistics'];
            $battles = Input::battles($stats['battles'] ?? null);
            if ($battles > PHP_INT_MAX - $statistics['battles']) {
                throw new \Edrard\WotRatings\Exception\CalculationFailed('Battle count overflow.');
            }
            $statistics['battles'] += $battles;
            foreach (['wins', 'damage_dealt', 'frags', 'spotted', 'dropped_capture_points'] as $field) {
                $value = Input::counter($stats, $field);
                if ($field === 'wins' && $value > $battles) {
                    throw new InvalidInput('Vehicle wins cannot exceed battles.');
                }
                $statistics[$field] += $value;
            }
            $tankBattles[] = ['tank_id' => Input::tankId($tank['tank_id'] ?? null), 'battles' => $battles];
        }
        return $this->wn8($statistics, $tankBattles, $expectedValues);
    }
}
