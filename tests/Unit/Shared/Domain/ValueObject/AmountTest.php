<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Shared\Domain\ValueObject\Amount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    public function testAcceptsZero(): void
    {
        self::assertSame(0, new Amount(0)->value);
    }

    public function testAcceptsPositiveValue(): void
    {
        self::assertSame(1500, new Amount(1500)->value);
    }

    public function testRejectsNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be greater than or equal to zero');

        new Amount(-1);
    }

    public function testSubclassesRejectNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccountDebit(-1);
    }

    public function testAddAmountSumsAllGivenAmounts(): void
    {
        $sum = new Amount(100)->addAmount(new Amount(20), new Amount(3));

        self::assertSame(123, $sum->value);
    }

    public function testAddAmountWithoutArgumentsReturnsEqualValue(): void
    {
        self::assertSame(100, new Amount(100)->addAmount()->value);
    }

    public function testAddAmountDoesNotMutateOriginal(): void
    {
        $amount = new Amount(100);

        $sum = $amount->addAmount(new Amount(50));

        self::assertNotSame($amount, $sum);
        self::assertSame(100, $amount->value);
        self::assertSame(150, $sum->value);
    }

    public function testAddAmountKeepsCallerType(): void
    {
        $sum = new AccountCredit(100)->addAmount(new Amount(50));

        self::assertSame(AccountCredit::class, $sum::class);
        self::assertSame(150, $sum->value);
    }

    public function testCopyCreatesNewInstanceOfCalledClass(): void
    {
        $source = new AccountCredit(700);

        $copy = AccountDebit::copy($source);

        self::assertSame(AccountDebit::class, $copy::class);
        self::assertSame(700, $copy->value);
    }

    public function testCopyReturnsDistinctInstance(): void
    {
        $source = new Amount(700);

        self::assertNotSame($source, Amount::copy($source));
    }

    public function testCastsToString(): void
    {
        self::assertSame('1500', (string) new Amount(1500));
    }
}
