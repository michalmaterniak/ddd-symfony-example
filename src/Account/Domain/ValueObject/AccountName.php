<?php

declare(strict_types=1);

namespace App\Account\Domain\ValueObject;

readonly class AccountName
{
    public function __construct(public string $name)
    {
    }
}
