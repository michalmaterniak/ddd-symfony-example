<?php

declare(strict_types=1);

namespace App\Account\Application\Command;

use App\Account\Application\Handler\CreateAccountHandler;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Application\Command;

/**
 * @see CreateAccountHandler
 */
readonly class CreateAccountCommand implements Command
{
    public function __construct(
        public AccountId $accountId,
        public AccountName $accountName,
        public AccountDescription $accountDescription,
    ) {
    }
}
