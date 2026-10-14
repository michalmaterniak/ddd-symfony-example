<?php

declare(strict_types=1);

namespace App\Account\Application\Listener;

use App\Account\Domain\Event\AccountDebitUpdated;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountEntrySide;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
class AccountDebitAddedListener
{
    public function __construct(
        private AccountRepository $accountRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountDebitUpdated $event): void
    {
        if (null === $this->accountRepository->findById($event->accountId)) {
            $this->logger->critical('Account not found', ['accountId' => $event->accountId]);

            return;
        }

        $this->logger->info(
            'Account debit updated',
            [
                'side' => AccountEntrySide::DEBIT,
                'accountId' => $event->accountId,
                'amount' => $event->entryDebit,
                'effectiveAt' => $event->effectiveAt,
                'accountEntryId' => $event->accountEntryId,
            ],
        );
    }
}
