<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Types;

use App\Shared\Domain\ValueObject\Amount;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

/**
 * Maps an {@see Amount} to a BIGINT column.
 */
#[AsDbalType(self::NAME)]
final class AmountType extends Type
{
    public const string NAME = 'amount';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBigIntTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Amount
    {
        if (null === $value || $value instanceof Amount) {
            return $value;
        }

        if (!\is_int($value) && !(\is_string($value) && is_numeric($value))) {
            throw InvalidType::new($value, self::NAME, ['null', 'int', 'numeric-string']);
        }

        try {
            return new Amount((int) $value);
        } catch (InvalidArgumentException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Amount) {
            throw InvalidType::new($value, self::NAME, ['null', Amount::class]);
        }

        return $value->value;
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }
}
