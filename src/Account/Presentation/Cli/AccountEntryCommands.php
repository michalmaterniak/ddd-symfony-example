<?php

declare(strict_types=1);

namespace App\Account\Presentation\Cli;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\MessageModeOption;
use App\Shared\Application\Command as ApplicationCommand;
use Closure;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:account')]
readonly class AccountEntryCommands
{
    public function __construct(
        private CommandBus $commandBus,
    ) {
    }

    #[AsCommand('add-credit', description: 'Adds a credit entry to the account')]
    public function addCredit(
        SymfonyStyle $io,
        #[Argument(description: 'Account id (UUID v4)')]
        string $accountId,
        #[Argument(description: 'Credit amount (in minor units, e.g. cents)')]
        int $credit,
        #[Option(
            description: 'Date the entry is effective at (any format accepted by DateTimeImmutable), defaults to now',
        )]
        ?DateTimeImmutable $effectiveAt = null,
    ): int {
        $accountEntryId = new AccountEntryId();

        return $this->dispatchEntry(
            $io,
            AccountEntrySide::CREDIT,
            $accountId,
            $credit,
            $effectiveAt,
            static fn (DateTimeImmutable $effectiveAt): ApplicationCommand => new AddAccountEntryCreditCommand(
                accountEntryId: $accountEntryId,
                accountId: new AccountId($accountId),
                credit: new AccountCredit($credit),
                effectiveAt: $effectiveAt,
            ),
        );
    }

    #[AsCommand('add-debit', description: 'Adds a debit entry to the account')]
    public function addDebit(
        SymfonyStyle $io,
        #[Argument(description: 'Account id (UUID v4)')]
        string $accountId,
        #[Argument(description: 'Debit amount (in minor units, e.g. cents)')]
        int $debit,
        #[Option(
            description: 'Date the entry is effective at (any format accepted by DateTimeImmutable), defaults to now',
        )]
        ?DateTimeImmutable $effectiveAt = null,
    ): int {
        $accountEntryId = new AccountEntryId();

        return $this->dispatchEntry(
            $io,
            AccountEntrySide::DEBIT,
            $accountId,
            $debit,
            $effectiveAt,
            static fn (DateTimeImmutable $effectiveAt): ApplicationCommand => new AddAccountEntryDebitCommand(
                accountEntryId: $accountEntryId,
                accountId: new AccountId($accountId),
                debit: new AccountDebit($debit),
                effectiveAt: $effectiveAt,
            ),
        );
    }

    /**
     * @param Closure(DateTimeImmutable): ApplicationCommand $createCommand
     */
    private function dispatchEntry(
        SymfonyStyle $io,
        AccountEntrySide $side,
        string $accountId,
        int $amount,
        ?DateTimeImmutable $effectiveAt,
        Closure $createCommand,
    ): int {
        $error = $this->validate($side, $accountId, $amount);
        if (null !== $error) {
            $io->error($error);

            return Command::INVALID;
        }

        $effectiveAt ??= new DateTimeImmutable();

        $this->commandBus->dispatch($createCommand($effectiveAt), MessageModeOption::sync());

        $io->success(\sprintf(
            'Added %s of %d to account %s (effective at %s).',
            $side->value,
            $amount,
            $accountId,
            $effectiveAt->format(\DATE_ATOM),
        ));

        return Command::SUCCESS;
    }

    private function validate(AccountEntrySide $side, string $accountId, int $amount): ?string
    {
        if (false === AccountId::isValid($accountId)) {
            return \sprintf('Invalid account id "%s".', $accountId);
        }

        if ($amount <= 0) {
            return \sprintf('%s must be greater than zero.', ucfirst($side->value));
        }

        return null;
    }
}
