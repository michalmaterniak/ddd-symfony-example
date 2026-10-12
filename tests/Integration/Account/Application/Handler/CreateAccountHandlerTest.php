<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Application\Handler;

use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Application\Handler\CreateAccountHandler;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateAccountHandlerTest extends KernelTestCase
{
    private CreateAccountHandler $handler;
    private AccountRepository $accountRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->handler = self::getContainer()->get(CreateAccountHandler::class);
        $this->accountRepository = self::getContainer()->get(AccountRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function createsAccount(): void
    {
        // given
        $accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');

        // when
        $this->handler->__invoke(
            new CreateAccountCommand(
                $accountId,
                new AccountName('Savings'),
                new AccountDescription('savings account'),
            ),
        );

        // then
        $this->entityManager->clear();
        $account = $this->accountRepository->findById($accountId);
        self::assertInstanceOf(Account::class, $account);
        self::assertTrue($accountId->equals($account->getId()));

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT name, description FROM account WHERE id = :id',
            ['id' => $accountId->toString()],
        );
        self::assertSame(['name' => 'Savings', 'description' => 'savings account'], $row);
    }

    #[Test]
    public function throwsExceptionWhenAccountNameAlreadyExists(): void
    {
        // given
        $this->handler->__invoke(
            new CreateAccountCommand(new AccountId(), new AccountName('Savings'), new AccountDescription('first')),
        );
        $this->entityManager->clear();

        // then
        $this->expectException(UniqueConstraintViolationException::class);

        // when
        $this->handler->__invoke(
            new CreateAccountCommand(new AccountId(), new AccountName('Savings'), new AccountDescription('second')),
        );
    }

    #[Test]
    public function throwsExceptionWhenAccountIdAlreadyExists(): void
    {
        // given
        $accountId = new AccountId();
        $this->handler->__invoke(
            new CreateAccountCommand($accountId, new AccountName('First'), new AccountDescription('first')),
        );
        $this->entityManager->clear();

        // then
        $this->expectException(UniqueConstraintViolationException::class);

        // when
        $this->handler->__invoke(
            new CreateAccountCommand($accountId, new AccountName('Second'), new AccountDescription('second')),
        );
    }
}
