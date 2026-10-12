<?php

declare(strict_types=1);

namespace App\Account\Application\Listener;

use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountEntrySide;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
class AccountCreditAddedListener
{
    public function __construct(
        private AccountRepository $accountRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountCreditUpdated $event): void
    {
        if (null === $this->accountRepository->findById($event->accountId)) {
            $this->logger->critical('Account not found', ['accountId' => $event->accountId]);

            return;
        }

        $this->logger->info(
            'Account credit updated',
            [
                'side' => AccountEntrySide::CREDIT,
                'accountId' => $event->accountId,
                'amount' => $event->entryCredit,
                'effectiveAt' => $event->effectiveAt,
                'accountEntryId' => $event->accountEntryId,
            ],
        );
    }
}
