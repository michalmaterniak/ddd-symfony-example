<?php

declare(strict_types=1);

namespace App\Account\Domain\Dto;

use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Domain\ValueObject\Amount;
use DateTimeImmutable;

readonly class CreateAccountEntryDto
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountId $accountId,
        public DateTimeImmutable $effectiveAt,
        public Amount $amount,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
