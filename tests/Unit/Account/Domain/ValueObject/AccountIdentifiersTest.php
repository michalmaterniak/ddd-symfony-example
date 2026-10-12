<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Domain\ValueObject;

use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;

final class AccountIdentifiersTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<UuidV4>}>
     */
    public static function idClasses(): iterable
    {
        yield 'account id' => [AccountId::class];
        yield 'account entry id' => [AccountEntryId::class];
    }

    /**
     * @param class-string<UuidV4> $class
     */
    #[DataProvider('idClasses')]
    public function testGeneratesUniqueUuidV4(string $class): void
    {
        $first = new $class();
        $second = new $class();

        self::assertTrue(UuidV4::isValid($first->toRfc4122()));
        self::assertFalse($first->equals($second));
    }

    /**
     * @param class-string<UuidV4> $class
     */
    #[DataProvider('idClasses')]
    public function testIsCreatedFromString(string $class): void
    {
        $id = $class::fromString('9fc62b77-8dc6-4493-b245-6020d30573bd');

        self::assertInstanceOf($class, $id);
        self::assertSame('9fc62b77-8dc6-4493-b245-6020d30573bd', $id->toRfc4122());
    }

    /**
     * @param class-string<UuidV4> $class
     */
    #[DataProvider('idClasses')]
    public function testRejectsInvalidUuid(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);

        new $class('not-a-uuid');
    }

    /**
     * @param class-string<UuidV4> $class
     */
    #[DataProvider('idClasses')]
    public function testRejectsUuidOfOtherVersion(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);

        // UUID v7
        new $class('01922d6e-4b8a-7c3e-9f00-1a2b3c4d5e6f');
    }

    public function testAccountNameKeepsValue(): void
    {
        self::assertSame('Savings', new AccountName('Savings')->name);
    }
}
