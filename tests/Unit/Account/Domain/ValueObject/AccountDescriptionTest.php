<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Domain\ValueObject;

use App\Account\Domain\ValueObject\AccountDescription;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccountDescriptionTest extends TestCase
{
    public function testAcceptsEmptyDescription(): void
    {
        self::assertSame('', new AccountDescription('')->value);
    }

    public function testAcceptsDescriptionAtMaxLength(): void
    {
        $value = str_repeat('a', 25500);

        self::assertSame($value, new AccountDescription($value)->value);
    }

    public function testRejectsDescriptionOverMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Account description must be less than 25500 characters');

        new AccountDescription(str_repeat('a', 25501));
    }

    public function testLengthIsCountedInBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 12751 two-byte characters = 25502 bytes
        new AccountDescription(str_repeat('ą', 12751));
    }
}
