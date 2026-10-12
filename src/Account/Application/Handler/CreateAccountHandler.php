<?php

declare(strict_types=1);

namespace App\Account\Application\Handler;

use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Shared\Application\Bus\EventBus;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
readonly class CreateAccountHandler
{
    public function __construct(
        private AccountRepository $accountRepository,
        private EventBus $eventBus,
    ) {
    }

    public function __invoke(
        CreateAccountCommand $command,
    ): void {
        $account = Account::create(
            new CreateAccountDto(
                $command->accountId,
                $command->accountName,
                $command->accountDescription,
                Clock::get()->now(),
            ),
        );

        $this->accountRepository->new($account);
        $this->eventBus->publish($account->pullDomainEvents());
    }
}
