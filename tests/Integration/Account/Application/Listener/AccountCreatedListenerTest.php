<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Application\Listener;

use App\Account\Application\Listener\AccountCreatedListener;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Exception\AccountCreationException;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;

final class AccountCreatedListenerTest extends KernelTestCase
{
    private AccountCreatedListener $listener;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->listener = self::getContainer()->get(AccountCreatedListener::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function acceptsEventOfPersistedAccount(): void
    {
        // given
        $accountId = new AccountId();
        $account = Account::create(
            new CreateAccountDto(
                $accountId,
                new AccountName('Savings'),
                new AccountDescription('savings'),
                Clock::get()->now(),
            ),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // when
        $this->listener->__invoke($this->createEvent($accountId));

        // then
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function throwsExceptionWhenAccountWasNotPersisted(): void
    {
        // given
        $accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');

        // when
        try {
            $this->listener->__invoke($this->createEvent($accountId));
            self::fail('AccountCreationException was not thrown');
        } catch (AccountCreationException $exception) {
            // then
            self::assertSame('Account creation failed', $exception->getMessage());
            self::assertSame(['accountId' => $accountId->toString()], $exception->getDomainData());
        }
    }

    private function createEvent(AccountId $accountId): AccountCreated
    {
        return new AccountCreated($accountId, new AccountName('Savings'), new DateTimeImmutable('2026-09-01 00:00:00'));
    }
}
