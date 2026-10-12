<?php

declare(strict_types=1);

namespace App\Account\Domain\Event;

use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

readonly class AccountDebitUpdated implements DomainEvent
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountId $accountId,
        public AccountDebit $entryDebit,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
