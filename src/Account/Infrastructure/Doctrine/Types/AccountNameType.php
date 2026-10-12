<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Types;

use App\Account\Domain\ValueObject\AccountName;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

#[AsDbalType(self::NAME)]
final class AccountNameType extends Type
{
    public const string NAME = 'account_name';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AccountName
    {
        if (null === $value || $value instanceof AccountName) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string']);
        }

        return new AccountName($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof AccountName) {
            throw InvalidType::new($value, self::NAME, ['null', AccountName::class]);
        }

        return $value->name;
    }
}
