<?php

declare(strict_types=1);

namespace App\Account\Domain\ValueObject;

enum AccountEntrySide: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}
