<?php

declare(strict_types=1);

namespace App\Account\Application\Command;

use App\Account\Application\Handler\AddAccountDebitHandler;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Command;
use DateTimeImmutable;

/**
 * @see AddAccountDebitHandler
 */
readonly class AddAccountEntryDebitCommand implements Command
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountId $accountId,
        public AccountDebit $debit,
        public DateTimeImmutable $effectiveAt,
    ) {
    }
}
