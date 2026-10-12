<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Domain\Entity;

use App\Account\Domain\Dto\CreateAccountEntryDto;
use App\Account\Domain\Entity\AccountEntry;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Domain\ValueObject\Amount;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccountEntryTest extends TestCase
{
    private AccountId $accountId;
    private DateTimeImmutable $effectiveAt;

    protected function setUp(): void
    {
        $this->accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');
        $this->effectiveAt = new DateTimeImmutable('2026-10-01 12:00:00');
    }

    #[Test]
    public function createCreditBuildsCreditEntry(): void
    {
        // when
        $entry = AccountEntry::createCredit($this->createDto(250));

        // then
        self::assertSame(AccountEntrySide::CREDIT, $entry->side());
        self::assertSame(250, $entry->credit()->value);
        self::assertTrue($this->accountId->equals($entry->accountId()));
        self::assertSame($this->effectiveAt, $entry->effectiveAt());
    }

    #[Test]
    public function createDebitBuildsDebitEntry(): void
    {
        // when
        $entry = AccountEntry::createDebit($this->createDto(250));

        // then
        self::assertSame(AccountEntrySide::DEBIT, $entry->side());
        self::assertSame(250, $entry->debit()->value);
        self::assertTrue($this->accountId->equals($entry->accountId()));
        self::assertSame($this->effectiveAt, $entry->effectiveAt());
    }

    #[Test]
    public function creditEntryHasNoDebit(): void
    {
        // given
        $entry = AccountEntry::createCredit($this->createDto(250));

        // then
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Account entry is a credit');

        // when
        $entry->debit();
    }

    #[Test]
    public function debitEntryHasNoCredit(): void
    {
        // given
        $entry = AccountEntry::createDebit($this->createDto(250));

        // then
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Account entry is a debit');

        // when
        $entry->credit();
    }

    private function createDto(int $amount): CreateAccountEntryDto
    {
        return new CreateAccountEntryDto(
            new AccountEntryId(),
            $this->accountId,
            $this->effectiveAt,
            new Amount($amount),
            new DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }
}
