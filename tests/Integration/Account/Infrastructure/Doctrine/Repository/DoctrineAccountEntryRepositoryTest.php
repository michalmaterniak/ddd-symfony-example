<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Infrastructure\Doctrine\Repository;

use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Dto\CreateAccountEntryDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Entity\AccountEntry;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Account\Infrastructure\Doctrine\Repository\DoctrineAccountEntryRepository;
use App\Shared\Domain\ValueObject\Amount;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;

final class DoctrineAccountEntryRepositoryTest extends KernelTestCase
{
    private DoctrineAccountEntryRepository $repository;
    private EntityManagerInterface $entityManager;
    private AccountId $accountId;

    protected function setUp(): void
    {
        $this->repository = self::getContainer()->get(DoctrineAccountEntryRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->accountId = new AccountId();
        self::getContainer()->get(AccountRepository::class)->new(Account::create(
            new CreateAccountDto(
                $this->accountId,
                new AccountName('Savings'),
                new AccountDescription('savings'),
                Clock::get()->now(),
            ),
        ));
    }

    #[Test]
    public function newPersistsCreditEntry(): void
    {
        // given
        $entryId = new AccountEntryId();

        // when
        $this->repository->new(AccountEntry::createCredit($this->createDto($entryId, 250)));

        // then
        self::assertSame(
            [
                'account_id' => $this->accountId->toRfc4122(),
                'amount' => 250,
                'side' => 'credit',
                'effective_at' => '2026-10-01 10:00:00+00',
                'created_at' => '2026-10-12 08:00:00+00',
            ],
            $this->fetchRow($entryId),
        );
    }

    #[Test]
    public function newPersistsDebitEntry(): void
    {
        // given
        $entryId = new AccountEntryId();

        // when
        $this->repository->new(AccountEntry::createDebit($this->createDto($entryId, 70)));

        // then
        $row = $this->fetchRow($entryId);
        self::assertIsArray($row);
        self::assertSame('debit', $row['side']);
        self::assertSame(70, $row['amount']);
    }

    #[Test]
    public function keepsTheMomentOfEffectiveAtGivenInAnotherTimeZone(): void
    {
        // given
        $entryId = new AccountEntryId();
        $effectiveAt = new DateTimeImmutable('2026-10-01 10:00:00+05:00');

        // when
        $this->repository->new(AccountEntry::createCredit(new CreateAccountEntryDto(
            $entryId,
            $this->accountId,
            $effectiveAt,
            new Amount(250),
            new DateTimeImmutable('2026-10-12 10:00:00'),
        )));
        $this->entityManager->clear();

        // then
        $entry = $this->entityManager->find(AccountEntry::class, $entryId);
        self::assertInstanceOf(AccountEntry::class, $entry);
        self::assertSame($effectiveAt->getTimestamp(), $entry->effectiveAt()->getTimestamp());
    }

    #[Test]
    public function rejectsEntryWithExistingId(): void
    {
        // given
        $entryId = new AccountEntryId();
        $this->repository->new(AccountEntry::createCredit($this->createDto($entryId, 250)));
        $this->entityManager->clear();

        // then
        $this->expectException(UniqueConstraintViolationException::class);

        // when
        $this->repository->new(AccountEntry::createCredit($this->createDto($entryId, 250)));
    }

    private function createDto(AccountEntryId $entryId, int $amount): CreateAccountEntryDto
    {
        return new CreateAccountEntryDto(
            $entryId,
            $this->accountId,
            new DateTimeImmutable('2026-10-01 12:00:00'),
            new Amount($amount),
            new DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRow(AccountEntryId $entryId): array|false
    {
        return $this->entityManager->getConnection()->fetchAssociative(
            'SELECT account_id, amount, side, effective_at, created_at FROM account_entry WHERE id = :id',
            ['id' => $entryId->toRfc4122()],
        );
    }
}
