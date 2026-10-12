<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Domain\Entity;

use App\Account\Domain\Dto\AddAccountCreditDto;
use App\Account\Domain\Dto\AddAccountDebitDto;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Event\AccountDebitUpdated;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AccountTest extends TestCase
{
    use ClockSensitiveTrait;

    private AccountId $accountId;
    private DateTimeImmutable $effectiveAt;

    protected function setUp(): void
    {
        self::mockTime('2026-10-12 10:00:00');
        $this->accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');
        $this->effectiveAt = new DateTimeImmutable('2026-10-01 12:00:00');
    }

    #[Test]
    public function createsAccountWithNoEntries(): void
    {
        // when
        $account = $this->createAccount();

        // then
        self::assertTrue($this->accountId->equals($account->getId()));
        self::assertSame([], $account->getNewAccountEntries());
    }

    #[Test]
    public function createRecordsAccountCreatedEvent(): void
    {
        // when
        $account = $this->createAccount();

        // then
        self::assertEquals(
            [
                new AccountCreated(
                    $this->accountId,
                    new AccountName('Savings'),
                    new DateTimeImmutable('2026-10-12 10:00:00'),
                ),
            ],
            $account->pullDomainEvents(),
        );
    }

    #[Test]
    public function recordCreditCreatesCreditEntryForThisAccount(): void
    {
        // given
        $account = $this->existingAccount();

        // when
        $account->recordCredit($this->creditDto(150));

        // then
        $entries = $account->getNewAccountEntries();
        self::assertCount(1, $entries);
        self::assertSame(AccountEntrySide::CREDIT, $entries[0]->side());
        self::assertSame(150, $entries[0]->credit()->value);
        self::assertTrue($this->accountId->equals($entries[0]->accountId()));
        self::assertEquals($this->effectiveAt, $entries[0]->effectiveAt());
    }

    #[Test]
    public function recordCreditRecordsCreditUpdatedEvent(): void
    {
        // given
        $account = $this->existingAccount();
        $entryId = new AccountEntryId();

        // when
        $account->recordCredit(new AddAccountCreditDto($entryId, new AccountCredit(150), $this->effectiveAt));

        // then
        self::assertEquals(
            [
                new AccountCreditUpdated(
                    $entryId,
                    $this->accountId,
                    new AccountCredit(150),
                    $this->effectiveAt,
                    new DateTimeImmutable('2026-10-12 10:00:00'),
                ),
            ],
            $account->pullDomainEvents(),
        );
    }

    #[Test]
    public function recordDebitCreatesDebitEntryForThisAccount(): void
    {
        // given
        $account = $this->existingAccount();

        // when
        $account->recordDebit($this->debitDto(70));

        // then
        $entries = $account->getNewAccountEntries();
        self::assertCount(1, $entries);
        self::assertSame(AccountEntrySide::DEBIT, $entries[0]->side());
        self::assertSame(70, $entries[0]->debit()->value);
        self::assertTrue($this->accountId->equals($entries[0]->accountId()));
        self::assertEquals($this->effectiveAt, $entries[0]->effectiveAt());
    }

    #[Test]
    public function recordDebitRecordsDebitUpdatedEvent(): void
    {
        // given
        $account = $this->existingAccount();
        $entryId = new AccountEntryId();

        // when
        $account->recordDebit(new AddAccountDebitDto($entryId, new AccountDebit(70), $this->effectiveAt));

        // then
        self::assertEquals(
            [
                new AccountDebitUpdated(
                    $entryId,
                    $this->accountId,
                    new AccountDebit(70),
                    $this->effectiveAt,
                    new DateTimeImmutable('2026-10-12 10:00:00'),
                ),
            ],
            $account->pullDomainEvents(),
        );
    }

    #[Test]
    public function recordsEntriesAndEventsInCallOrder(): void
    {
        // given
        $account = $this->existingAccount();

        // when
        $account->recordCredit($this->creditDto(150));
        $account->recordDebit($this->debitDto(70));

        // then
        $entries = $account->getNewAccountEntries();
        self::assertSame(
            [AccountEntrySide::CREDIT, AccountEntrySide::DEBIT],
            [$entries[0]->side(), $entries[1]->side()],
        );

        $events = $account->pullDomainEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(AccountCreditUpdated::class, $events[0]);
        self::assertInstanceOf(AccountDebitUpdated::class, $events[1]);
    }

    #[Test]
    public function eventsCarryOnlyTheAmountOfTheirOwnEntry(): void
    {
        // given
        $account = $this->existingAccount();

        // when
        $account->recordCredit($this->creditDto(100));
        $account->recordCredit($this->creditDto(50));
        $account->recordDebit($this->debitDto(30));
        $account->recordDebit($this->debitDto(20));

        // then
        $events = $account->pullDomainEvents();
        self::assertCount(4, $events);
        self::assertInstanceOf(AccountCreditUpdated::class, $events[0]);
        self::assertInstanceOf(AccountCreditUpdated::class, $events[1]);
        self::assertInstanceOf(AccountDebitUpdated::class, $events[2]);
        self::assertInstanceOf(AccountDebitUpdated::class, $events[3]);
        self::assertSame(
            [100, 50, 30, 20],
            [
                $events[0]->entryCredit->value,
                $events[1]->entryCredit->value,
                $events[2]->entryDebit->value,
                $events[3]->entryDebit->value,
            ],
        );
    }

    #[Test]
    public function pullDomainEventsEmptiesRecordedEvents(): void
    {
        // given
        $account = $this->existingAccount();
        $account->recordCredit($this->creditDto(150));
        $account->pullDomainEvents();

        // when
        $events = $account->pullDomainEvents();

        // then
        self::assertSame([], $events);
    }

    private function creditDto(int $amount): AddAccountCreditDto
    {
        return new AddAccountCreditDto(new AccountEntryId(), new AccountCredit($amount), $this->effectiveAt);
    }

    private function debitDto(int $amount): AddAccountDebitDto
    {
        return new AddAccountDebitDto(new AccountEntryId(), new AccountDebit($amount), $this->effectiveAt);
    }

    /**
     * An account as loaded from the database: its creation event has already been published.
     */
    private function existingAccount(): Account
    {
        $account = $this->createAccount();
        $account->pullDomainEvents();

        return $account;
    }

    private function createAccount(): Account
    {
        return Account::create(
            new CreateAccountDto(
                $this->accountId,
                new AccountName('Savings'),
                new AccountDescription('savings'),
                Clock::get()->now(),
            ),
        );
    }
}
