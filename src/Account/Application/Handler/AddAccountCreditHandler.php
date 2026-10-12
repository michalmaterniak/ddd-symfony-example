<?php

declare(strict_types=1);

namespace App\Account\Application\Handler;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Domain\Dto\AddAccountCreditDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountEntryRepository;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Bus\EventBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler(bus: 'command.bus')]
readonly class AddAccountCreditHandler
{
    public function __construct(
        private AccountRepository $accountRepository,
        private AccountEntryRepository $accountEntryRepository,
        private EventBus $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddAccountEntryCreditCommand $command): void
    {
        $account = $this->getAccount($command->accountId);
        $account->recordCredit(
            new AddAccountCreditDto(
                accountEntryId: $command->accountEntryId,
                credit: $command->credit,
                effectiveAt: $command->effectiveAt,
            ),
        );

        $this->newAccountEntries($account);
        $this->eventBus->publish($account->pullDomainEvents());
    }

    private function newAccountEntries(Account $account): void
    {
        foreach ($account->getNewAccountEntries() as $accountEntry) {
            $this->accountEntryRepository->new($accountEntry);
        }
    }

    private function getAccount(AccountId $accountId): Account
    {
        $account = $this->accountRepository->findById($accountId);
        if (false === $account instanceof Account) {
            $this->logger->warning(
                'Account not found',
                [
                    'class' => static::class,
                    'accountId' => $accountId,
                ],
            );
            throw new UnrecoverableMessageHandlingException('Account not found');
        }

        return $account;
    }
}
