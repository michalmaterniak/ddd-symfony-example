<?php

declare(strict_types=1);

namespace App\Account\Domain\ValueObject;

use InvalidArgumentException;

readonly class AccountDescription
{
    public function __construct(
        public string $value,
    ) {
        if (\strlen($value) > 25500) {
            throw new InvalidArgumentException('Account description must be less than 25500 characters');
        }
    }
}
