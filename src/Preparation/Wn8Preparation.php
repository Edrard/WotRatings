<?php

declare(strict_types=1);

namespace Edrard\WotRatings\Preparation;

final readonly class Wn8Preparation
{
    private function __construct(public ?Wn8Input $input, public ?string $reason)
    {
    }

    public static function ready(Wn8Input $input): self
    {
        return new self($input, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, $reason);
    }
}
