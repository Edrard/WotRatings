<?php

declare(strict_types=1);

namespace Edrard\WotRatings\Exception;

final class PreparationUnavailable extends \DomainException
{
    public const MISSING_MODE_DATA = 1;
    public const BATTLE_COUNT_MISMATCH = 2;
}
