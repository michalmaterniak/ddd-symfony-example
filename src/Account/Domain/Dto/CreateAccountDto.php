<?php

declare(strict_types=1);

namespace App\Account\Domain\Dto;

use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use DateTimeImmutable;

readonly class CreateAccountDto
{
    public function __construct(
        public AccountId $id,
        public AccountName $name,
        public AccountDescription $description,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
