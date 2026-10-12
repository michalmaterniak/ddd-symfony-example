<?php

declare(strict_types=1);

namespace App\Account\Domain\Dto;

use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountEntryId;
use DateTimeImmutable;

readonly class AddAccountCreditDto
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountCredit $credit,
        public DateTimeImmutable $effectiveAt,
    ) {
    }
}
