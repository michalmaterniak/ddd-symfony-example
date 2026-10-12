<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Infrastructure\Doctrine\Repository;

use App\Account\Domain\Dto\AddAccountCreditDto;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Account\Infrastructure\Doctrine\Repository\DoctrineAccountRepository;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;

final class DoctrineAccountRepositoryTest extends KernelTestCase
{
    use DispatchedMessagesAssertionTrait;

    private DoctrineAccountRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->repository = self::getContainer()->get(DoctrineAccountRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function newPersistsAccount(): void
    {
        // given
        $accountId = new AccountId();

        // when
        $this->repository->new($this->createAccount($accountId, 'Savings'));

        // then
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT name, description FROM account WHERE id = :id',
            ['id' => $accountId->toRfc4122()],
        );
        self::assertSame(['name' => 'Savings', 'description' => 'savings'], $row);
    }

    #[Test]
    public function findByIdReturnsNullForUnknownAccount(): void
    {
        self::assertNull($this->repository->findById(new AccountId()));
    }

    #[Test]
    public function findByIdReturnsPersistedAccount(): void
    {
        // given
        $accountId = new AccountId();
        $this->repository->new($this->createAccount($accountId, 'Savings'));
        $this->repository->new($this->createAccount(new AccountId(), 'Other'));
        $this->entityManager->clear();

        // when
        $account = $this->repository->findById($accountId);

        // then
        self::assertInstanceOf(Account::class, $account);
        self::assertTrue($accountId->equals($account->getId()));
    }

    #[Test]
    public function findByAccountNameReturnsPersistedAccount(): void
    {
        // given
        $accountId = new AccountId();
        $this->repository->new($this->createAccount($accountId, 'Savings'));
        $this->repository->new($this->createAccount(new AccountId(), 'Other'));
        $this->entityManager->clear();

        // when
        $account = $this->repository->findByAccountName(new AccountName('Savings'));

        // then
        self::assertInstanceOf(Account::class, $account);
        self::assertTrue($accountId->equals($account->getId()));
    }

    #[Test]
    public function findByAccountNameReturnsNullForUnknownName(): void
    {
        self::assertNull($this->repository->findByAccountName(new AccountName('Unknown')));
    }

    #[Test]
    public function persistingDoesNotPublishRecordedEvents(): void
    {
        // given
        $account = $this->createAccount(new AccountId(), 'Savings');
        $this->repository->new($account);
        $account->recordCredit(
            new AddAccountCreditDto(new AccountEntryId(), new AccountCredit(10), new DateTimeImmutable()),
        );

        // when
        $this->repository->update($account);

        // then
        self::assertSame([], self::getDispatchedMessages('event.bus'));
        $events = $account->pullDomainEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(AccountCreated::class, $events[0]);
        self::assertInstanceOf(AccountCreditUpdated::class, $events[1]);
    }

    private function createAccount(AccountId $accountId, string $name): Account
    {
        return Account::create(
            new CreateAccountDto(
                $accountId,
                new AccountName($name),
                new AccountDescription('savings'),
                Clock::get()->now(),
            ),
        );
    }
}
