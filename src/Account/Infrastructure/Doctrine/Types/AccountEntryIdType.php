<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Types;

use App\Account\Domain\ValueObject\AccountEntryId;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;

#[AsDbalType(self::NAME)]
final class AccountEntryIdType extends AbstractUidType
{
    public const string NAME = 'account_entry_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return AccountEntryId::class;
    }
}
