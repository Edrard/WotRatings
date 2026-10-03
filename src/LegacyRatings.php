<?php

declare(strict_types=1);

namespace Edrard\WotRatings;

use Edrard\WotRatings\Internal\Input;

/** Historical formulas from the owner's rating.php; not current XVM percentile scales. */
final class LegacyRatings
{
    /** @param array<string, mixed> $statistics Flat totals. */
    public function wn7(array $statistics, float $averageTier): int
    {
        $tier = Input::number($averageTier, 'averageTier');
        $n = Input::battles($statistics['battles'] ?? null);
        $wins = Input::number($statistics['wins'] ?? null, 'wins', maximum: $n);
        $frags = Input::counter($statistics, 'frags');
        $damage = Input::counter($statistics, 'damage_dealt');
        $spotted = Input::counter($statistics, 'spotted');
        $defence = Input::counter($statistics, 'dropped_capture_points');
        if ($n === 0 || $tier === 0.0) {
            return 0;
        }
        $win = round($wins * 100 / $n, 1);
        return Input::rounded($frags / $n * (1240 - 1040 / pow(min($tier, 6), 0.164))
            + $damage / $n * 530 / (184 * exp(0.24 * $tier) + 130)
            + $spotted / $n * 125 * min($tier, 3) / 3 + min($defence / $n, 2.2) * 100
            + (185 / (0.17 + exp(($win - 35) * -0.134)) - 500) * 0.45
            - ((5 - min($tier, 5)) * 125) / (1 + exp(($tier - pow($n / 220, 3 / $tier)) * 1.5)));
    }

    /** @param array<string, mixed> $statistics */
    public function broni(array $statistics): int
    {
        $n = Input::battles($statistics['battles'] ?? null);
        $wins = Input::number($statistics['wins'] ?? null, 'wins', maximum: $n);
        $xp = Input::counter($statistics, 'xp');
        $damage = Input::counter($statistics, 'damage_dealt');
        $frags = Input::counter($statistics, 'frags');
        $spotted = Input::counter($statistics, 'spotted');
        $defence = Input::counter($statistics, 'dropped_capture_points');
        $capture = Input::counter($statistics, 'capture_points');
        if ($n === 0) {
            return 0;
        }
        return Input::rounded(log($n) / 10 * ($xp / $n + $damage / $n
            * (round($wins * 100 / $n, 1) / 100 * 2 + $frags / $n * 0.9
                + $spotted / $n * 0.5 + $defence / $n * 0.5 + $capture / $n * 0.5)));
    }

    /**
     * @param array<string, mixed> $statistics battles, wins, damage_dealt
     * @param array<int|string, mixed> $tankBattles List of battles and nominal_damage records.
     */
    public function noobmeter(array $statistics, array $tankBattles, float $averageTier): int
    {
        $tier = Input::number($averageTier, 'averageTier');
        $n = Input::battles($statistics['battles'] ?? null);
        $wins = Input::number($statistics['wins'] ?? null, 'wins', maximum: $n);
        $damage = Input::counter($statistics, 'damage_dealt');
        if (!array_is_list($tankBattles)) {
            throw new \Edrard\WotRatings\Exception\InvalidInput('tankBattles must be a list.');
        }
        $sum = 0.0;
        $weighted = 0.0;
        foreach ($tankBattles as $tank) {
            if (!is_array($tank)) {
                throw new \Edrard\WotRatings\Exception\InvalidInput('Tank battle records must be arrays.');
            }
            $battles = Input::battles($tank['battles'] ?? null);
            $nominal = Input::number($tank['nominal_damage'] ?? null, 'nominal_damage');
            $sum += $battles;
            $weighted += $battles * $nominal;
        }
        Input::finite($weighted);
        if ($n === 0 || $sum === 0.0 || $weighted === 0.0) {
            return 0;
        }
        // The legacy formula consumes total damage, a win fraction and scaled nominal damage.
        $rating = 500 * ($wins / $n) / 0.4856 + 1000 * $damage / (($n / $sum) * $weighted);
        if ($rating > 1900) {
            $rating -= ($rating - 1900) * sqrt(max(0, 1 - $tier / 7, 1 - $n / 2000));
        } elseif ($rating > 1500) {
            $rating -= ($rating - 1500) * sqrt(max(0, 1 - $tier / 6, 1 - $n / 500));
        }
        return Input::rounded($rating);
    }

    public function xeff(float $rating): float
    {
        $x = Input::number($rating, 'rating', minimum: -PHP_FLOAT_MAX);
        return $x < 420 ? 0.0 : Input::finite(max(min($x * ($x * ($x * ($x * ($x * (0.000000000000000045254 * $x - 0.00000000000033131) + 0.00000000094164) - 0.0000013227) + 0.00095664) - 0.2598) + 13.23, 100), 0));
    }

    public function xwn6(float $rating): float
    {
        $x = Input::number($rating, 'rating', minimum: -PHP_FLOAT_MAX);
        return $x > 2160 ? 100.0 : Input::finite(max(min($x * ($x * ($x * (-0.00000000001268 * $x + 0.00000005147) - 0.00006418) + 0.07576) - 7.25, 100), 0));
    }

    public function xwn7(float $rating): float
    {
        $x = Input::number($rating, 'rating', minimum: -PHP_FLOAT_MAX);
        return $x > 2250 ? 100.0 : Input::finite(max(min($x * ($x * ($x * (-0.0000000000083 * $x + 0.0000000287) - 0.000024) + 0.049) - 2.7, 100), 0));
    }

    public function xnm(float $rating): float
    {
        $x = Input::number($rating, 'rating', minimum: -PHP_FLOAT_MAX);
        return $x > 2180 ? 100.0 : Input::finite(min(max($x * ($x * ($x * (-0.00000000008567 * $x + 0.0000004071) - 0.000689) + 0.5677) - 183, $x * (-0.0002002 * $x + 0.8732) - 852.3, 0), 100));
    }
}
