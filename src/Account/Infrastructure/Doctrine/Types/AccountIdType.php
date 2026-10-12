<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Types;

use App\Account\Domain\ValueObject\AccountId;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;

#[AsDbalType(self::NAME)]
final class AccountIdType extends AbstractUidType
{
    public const string NAME = 'account_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return AccountId::class;
    }
}
