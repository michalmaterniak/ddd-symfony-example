<?php

declare(strict_types=1);

namespace App\Account\Domain\Event;

use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

readonly class AccountCreated implements DomainEvent
{
    public function __construct(
        public AccountId $id,
        public AccountName $name,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
