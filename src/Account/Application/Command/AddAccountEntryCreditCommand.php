<?php

declare(strict_types=1);

namespace App\Account\Application\Command;

use App\Account\Application\Handler\AddAccountCreditHandler;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Command;
use DateTimeImmutable;

/**
 * @see AddAccountCreditHandler
 */
readonly class AddAccountEntryCreditCommand implements Command
{
    public function __construct(
        public AccountEntryId $accountEntryId,
        public AccountId $accountId,
        public AccountCredit $credit,
        public DateTimeImmutable $effectiveAt,
    ) {
    }
}
