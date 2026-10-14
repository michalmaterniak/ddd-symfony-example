<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use InvalidArgumentException;
use Stringable;

readonly class Amount implements Stringable
{
    final public function __construct(public int $value)
    {
        if (0 > $value) {
            throw new InvalidArgumentException('Amount must be greater than or equal to zero');
        }
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function addAmount(self ...$other): static
    {
        $sum = $this->value;
        foreach ($other as $amount) {
            $sum += $amount->value;
        }

        return new static($sum);
    }

    public static function copy(self $balance): static
    {
        return new static($balance->value);
    }
}
