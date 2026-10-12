<?php

declare(strict_types=1);

namespace App\Account\Presentation\Cli;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Bus\CommandBus;
use DateTimeImmutable;
use Generator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:account')]
readonly class StressTestForAccountUpdate
{
    public function __construct(
        private CommandBus $commandBus,
    ) {
    }

    #[AsCommand(
        'stress-test',
        description: 'Dispatches many credit/debit commands whose net sum equals the given amount',
    )]
    public function stressTest(
        SymfonyStyle $io,
        #[Argument(description: 'Account id (UUID v4)')]
        string $accountId,
        #[Argument(description: 'Net amount to distribute (in minor units, e.g. cents)')]
        int $amount,
        #[Option(description: 'Number of iterations, each dispatches one credit and one debit')]
        int $iterations = 1000,
        #[Option(
            description: 'Total of all debits as a percentage of the amount (credits are increased by the same total)',
        )]
        int $debitRatio = 50,
    ): int {
        $debitTotal = intdiv($amount, 100) * $debitRatio + intdiv(($amount % 100) * $debitRatio, 100);

        $error = $this->validate($accountId, $amount, $iterations, $debitRatio, $debitTotal);
        if (null !== $error) {
            $io->error($error);

            return Command::INVALID;
        }

        $creditTotal = $amount + $debitTotal;
        $credits = $this->split($creditTotal, $iterations);
        $debits = $this->split($debitTotal, $iterations);

        $io->progressStart($iterations);
        for ($i = 0; $i < $iterations; ++$i) {
            $messages = [
                new AddAccountEntryCreditCommand(
                    accountEntryId: new AccountEntryId(),
                    accountId: new AccountId($accountId),
                    credit: new AccountCredit($credits->current()),
                    effectiveAt: new DateTimeImmutable(),
                ),
            ];
            if ($debitTotal > 0) {
                $messages[] = new AddAccountEntryDebitCommand(
                    accountEntryId: new AccountEntryId(),
                    accountId: new AccountId($accountId),
                    debit: new AccountDebit($debits->current()),
                    effectiveAt: new DateTimeImmutable(),
                );
            }
            shuffle($messages);

            foreach ($messages as $message) {
                $this->commandBus->dispatch($message);
            }

            $credits->next();
            $debits->next();
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success(\sprintf(
            'Dispatched %d messages for account %s: credits %d, debits %d, net %d.',
            $iterations * ($debitTotal > 0 ? 2 : 1),
            $accountId,
            $creditTotal,
            $debitTotal,
            $amount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Splits $total into $parts positive integers summing exactly to $total.
     * Parts are randomized in pairs (+jitter / -jitter) so the sum stays intact without buffering.
     *
     * @return Generator<int, int>
     */
    private function split(int $total, int $parts): Generator
    {
        if (0 === $total) {
            return;
        }

        $base = intdiv($total, $parts);
        $remainder = $total % $parts;

        for ($i = 0; $i < $parts; $i += 2) {
            $first = $base + ($i < $remainder ? 1 : 0);
            if ($i + 1 === $parts) {
                yield $first;

                return;
            }

            $second = $base + ($i + 1 < $remainder ? 1 : 0);
            $jitter = random_int(0, $base - 1);

            yield $first + $jitter;
            yield $second - $jitter;
        }
    }

    private function validate(
        string $accountId,
        int $amount,
        int $iterations,
        int $debitRatio,
        int $debitTotal,
    ): ?string {
        if (false === AccountId::isValid($accountId)) {
            return \sprintf('Invalid account id "%s".', $accountId);
        }

        if ($iterations <= 0) {
            return 'Iterations must be greater than zero.';
        }

        if ($amount < $iterations) {
            return \sprintf('Amount must be at least %d (one minor unit per iteration).', $iterations);
        }

        if ($debitRatio < 0) {
            return 'Debit ratio must not be negative.';
        }

        if ($debitRatio > 0 && $debitTotal < $iterations) {
            return \sprintf(
                'Debit total %d is too small for %d iterations, increase the amount or debit ratio.',
                $debitTotal,
                $iterations,
            );
        }

        if ($debitTotal > \PHP_INT_MAX - $amount) {
            return 'Amount plus debit total exceeds the integer range.';
        }

        return null;
    }
}
