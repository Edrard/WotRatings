<?php

declare(strict_types=1);

namespace Edrard\WotRatings\Internal;

use Edrard\WotRatings\Exception\InvalidInput;

/** @internal */
final class ExpectedValues
{
    public const FIELDS = ['expDamage', 'expSpot', 'expFrag', 'expDef', 'expWinRate'];

    /**
     * @param array<int|string, mixed> $rows XVM data rows with IDNum, or a map keyed by tank ID.
     * @return array<int, array<string, float>>
     */
    public static function normalize(array $rows): array
    {
        if (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }
        if ($rows === []) {
            throw new InvalidInput('Expected-value table must not be empty.');
        }
        $list = array_is_list($rows);
        $result = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                throw new InvalidInput('Expected-value rows must be arrays.');
            }
            $id = Input::tankId($row['IDNum'] ?? ($list ? null : $key));
            if (isset($result[$id]) || (!$list && (string) $key !== (string) $id)) {
                throw new InvalidInput('Duplicate or conflicting expected-value tank ID.');
            }
            foreach (self::FIELDS as $field) {
                $value = Input::number($row[$field] ?? null, $field);
                if ($value <= 0 || ($field === 'expWinRate' && $value > 100)) {
                    throw new InvalidInput('Expected values must be positive; expWinRate uses percent (0, 100].');
                }
                $result[$id][$field] = $value;
            }
        }
        return $result;
    }
}
