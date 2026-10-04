<?php

declare(strict_types=1);

// Synthetic, offline records: one tank, tier 6, and two battle modes.
$statistics = static fn (int $battles): array => [
    'battles' => $battles, 'wins' => $battles / 2, 'damage_dealt' => $battles * 1000,
    'frags' => $battles, 'spotted' => $battles,
    'capture_points' => $battles, 'dropped_capture_points' => $battles,
];
$player = ['account_id' => 123, 'statistics' => ['all' => $statistics(100), 'random' => $statistics(100)]];
$short = [['account_id' => 123, 'tank_id' => 1, 'statistics' => ['battles' => 100]]];
$fullAll = $statistics(80);
$fullAll['damage_dealt'] *= 2;
$full = [['account_id' => 123, 'tank_id' => 1, 'all' => $fullAll, 'random' => $statistics(60)]];
$wrap = static fn (array $record): array => ['status' => 'ok', 'data' => [123 => $record]];

return [
    'player' => $player,
    'short' => $short,
    'full' => $full,
    'catalogue' => [1 => ['tier' => 6]],
    'expected' => [1 => ['expDamage' => 1000, 'expSpot' => 1, 'expFrag' => 1, 'expDef' => 1, 'expWinRate' => 50]],
    'accountInfoResponse' => $wrap($player),
    'accountTanksResponse' => $wrap($short),
    'tankStatsResponse' => $wrap($full),
];
