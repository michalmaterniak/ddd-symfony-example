<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Application\Listener;

use App\Account\Application\Listener\AccountCreditAddedListener;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountCreditAddedListenerTest extends KernelTestCase
{
    private AccountRepository $accountRepository;

    protected function setUp(): void
    {
        $this->accountRepository = self::getContainer()->get(AccountRepository::class);
    }

    #[Test]
    public function logsCriticalWhenAccountDoesNotExist(): void
    {
        // given
        $accountId = new AccountId();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with('Account not found', ['accountId' => $accountId]);

        // when
        new AccountCreditAddedListener($this->accountRepository, $logger)->__invoke($this->createEvent($accountId));
    }

    private function createEvent(AccountId $accountId): AccountCreditUpdated
    {
        return new AccountCreditUpdated(
            new AccountEntryId(),
            $accountId,
            new AccountCredit(100),
            new DateTimeImmutable('2026-10-01 12:00:00'),
            new DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }
}
