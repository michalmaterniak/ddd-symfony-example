<?php

declare(strict_types=1);

namespace App\Account\Domain\Repository;

use App\Account\Domain\Entity\Account;
use App\Account\Domain\ValueObject\AccountId;

interface AccountRepository
{
    public function new(Account $account): void;

    public function update(Account $account): void;

    public function findById(AccountId $id): ?Account;
}
