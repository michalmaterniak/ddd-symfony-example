<?php

declare(strict_types=1);

namespace App\Account\Domain\Event;

use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

readonly class AccountCreditUpdated implements DomainEvent
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountId $accountId,
        public AccountCredit $entryCredit,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
