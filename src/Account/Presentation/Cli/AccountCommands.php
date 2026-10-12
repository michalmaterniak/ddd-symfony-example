<?php

declare(strict_types=1);

namespace App\Account\Presentation\Cli;

use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\MessageModeOption;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:account')]
class AccountCommands
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
    }

    #[AsCommand('create', description: 'Creates a new account')]
    public function create(
        SymfonyStyle $io,
        #[Argument(description: 'Account name')]
        string $name,
        #[Argument(description: 'Account description')]
        string $description,
    ): int {
        $name = trim($name);
        if ('' === $name) {
            $io->error('Account name must not be empty.');

            return Command::INVALID;
        }

        $accountId = new AccountId();

        $this->commandBus->dispatch(
            new CreateAccountCommand(
                accountId: $accountId,
                accountName: new AccountName($name),
                accountDescription: new AccountDescription($description),
            ),
            MessageModeOption::sync(),
        );

        $io->success(\sprintf('Created account "%s" with id %s.', $name, $accountId->toRfc4122()));

        return Command::SUCCESS;
    }
}
