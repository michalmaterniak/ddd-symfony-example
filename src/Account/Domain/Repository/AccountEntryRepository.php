<?php

declare(strict_types=1);

namespace App\Account\Domain\Repository;

use App\Account\Domain\Entity\AccountEntry;

interface AccountEntryRepository
{
    public function new(AccountEntry $entry): void;
}
