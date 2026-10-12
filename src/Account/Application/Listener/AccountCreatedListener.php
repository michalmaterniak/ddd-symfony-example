<?php

declare(strict_types=1);

namespace App\Account\Application\Listener;

use App\Account\Domain\Entity\Account;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Exception\AccountCreationException;
use App\Account\Domain\Repository\AccountRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
readonly class AccountCreatedListener
{
    public function __construct(
        private AccountRepository $accountRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountCreated $accountCreated): void
    {
        $account = $this->accountRepository->findById($accountCreated->id);
        if (false === $account instanceof Account) {
            throw AccountCreationException::throw($accountCreated->id);
        }

        $this->logger->info('Account created', ['id' => $accountCreated->id]);
    }
}
