<?php

declare(strict_types=1);

namespace App\Account\Domain\Dto;

use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use DateTimeImmutable;

readonly class AddAccountDebitDto
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountDebit $debit,
        public DateTimeImmutable $effectiveAt,
    ) {
    }
}
