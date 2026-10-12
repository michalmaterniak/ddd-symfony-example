<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Types;

use App\Account\Domain\ValueObject\AccountDescription;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

#[AsDbalType(self::NAME)]
final class AccountDescriptionType extends Type
{
    public const string NAME = 'account_description';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AccountDescription
    {
        if (null === $value || $value instanceof AccountDescription) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string']);
        }

        try {
            return new AccountDescription($value);
        } catch (InvalidArgumentException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof AccountDescription) {
            throw InvalidType::new($value, self::NAME, ['null', AccountDescription::class]);
        }

        return $value->value;
    }
}
