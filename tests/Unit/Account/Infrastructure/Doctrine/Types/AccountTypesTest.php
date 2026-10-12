<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Infrastructure\Doctrine\Types;

use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Account\Infrastructure\Doctrine\Types\AccountDescriptionType;
use App\Account\Infrastructure\Doctrine\Types\AccountEntryIdType;
use App\Account\Infrastructure\Doctrine\Types\AccountIdType;
use App\Account\Infrastructure\Doctrine\Types\AccountNameType;
use App\Account\Infrastructure\Doctrine\Types\AmountType;
use App\Shared\Domain\ValueObject\Amount;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AccountTypesTest extends TestCase
{
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new PostgreSQLPlatform();
    }

    public function testAccountIdRoundTrip(): void
    {
        $type = new AccountIdType();
        $id = new AccountId();

        $dbValue = $type->convertToDatabaseValue($id, $this->platform);
        self::assertSame($id->toRfc4122(), $dbValue);

        $phpValue = $type->convertToPHPValue($dbValue, $this->platform);
        self::assertInstanceOf(AccountId::class, $phpValue);
        self::assertTrue($id->equals($phpValue));
        self::assertSame('UUID', $type->getSQLDeclaration([], $this->platform));
    }

    public function testAccountEntryIdRoundTrip(): void
    {
        $type = new AccountEntryIdType();
        $id = new AccountEntryId();

        $phpValue = $type->convertToPHPValue($type->convertToDatabaseValue($id, $this->platform), $this->platform);

        self::assertInstanceOf(AccountEntryId::class, $phpValue);
        self::assertTrue($id->equals($phpValue));
    }

    public function testAmountRoundTrip(): void
    {
        $type = new AmountType();

        self::assertSame(1500, $type->convertToDatabaseValue(new Amount(1500), $this->platform));

        $phpValue = $type->convertToPHPValue(1500, $this->platform);
        self::assertInstanceOf(Amount::class, $phpValue);
        self::assertSame(1500, $phpValue->value);

        // some drivers return integers as strings
        self::assertSame(700, $type->convertToPHPValue('700', $this->platform)?->value);

        // amounts beyond the 32-bit range (e.g. 100 000 000 000 PLN in grosze)
        self::assertSame(
            10_000_000_000_000,
            $type->convertToDatabaseValue(new Amount(10_000_000_000_000), $this->platform),
        );
        self::assertSame(10_000_000_000_000, $type->convertToPHPValue('10000000000000', $this->platform)?->value);

        self::assertSame('BIGINT', $type->getSQLDeclaration([], $this->platform));
    }

    public function testAccountNameRoundTrip(): void
    {
        $type = new AccountNameType();

        self::assertSame('Savings', $type->convertToDatabaseValue(new AccountName('Savings'), $this->platform));

        $phpValue = $type->convertToPHPValue('Savings', $this->platform);
        self::assertInstanceOf(AccountName::class, $phpValue);
        self::assertSame('Savings', $phpValue->name);
        self::assertSame('VARCHAR(255)', $type->getSQLDeclaration(['length' => 255], $this->platform));
    }

    public function testAccountDescriptionRoundTrip(): void
    {
        $type = new AccountDescriptionType();

        self::assertSame(
            'Main account',
            $type->convertToDatabaseValue(new AccountDescription('Main account'), $this->platform),
        );

        $phpValue = $type->convertToPHPValue('Main account', $this->platform);
        self::assertInstanceOf(AccountDescription::class, $phpValue);
        self::assertSame('Main account', $phpValue->value);
    }

    /**
     * @return iterable<string, array{Type}>
     */
    public static function types(): iterable
    {
        yield 'account_id' => [new AccountIdType()];
        yield 'account_entry_id' => [new AccountEntryIdType()];
        yield 'amount' => [new AmountType()];
        yield 'account_name' => [new AccountNameType()];
        yield 'account_description' => [new AccountDescriptionType()];
    }

    #[DataProvider('types')]
    public function testNullIsPassedThrough(Type $type): void
    {
        self::assertNull($type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    #[DataProvider('types')]
    public function testRejectsUnsupportedPhpValue(Type $type): void
    {
        $this->expectException(ConversionException::class);

        $type->convertToDatabaseValue(new stdClass(), $this->platform);
    }

    public function testRejectsInvalidUuidFromDatabase(): void
    {
        $this->expectException(ConversionException::class);

        new AccountIdType()->convertToPHPValue('not-a-uuid', $this->platform);
    }

    public function testRejectsNegativeAmountFromDatabase(): void
    {
        $this->expectException(ConversionException::class);

        new AmountType()->convertToPHPValue(-1, $this->platform);
    }

    public function testRejectsTooLongDescriptionFromDatabase(): void
    {
        $this->expectException(ConversionException::class);

        new AccountDescriptionType()->convertToPHPValue(str_repeat('a', 25501), $this->platform);
    }
}
