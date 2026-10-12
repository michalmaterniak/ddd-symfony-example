<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use InvalidArgumentException;
use Stringable;

readonly class Amount implements Stringable
{
    final public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Amount must be greater than or equal to zero');
        }
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

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
