<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account\Presentation\Cli;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StressTestForAccountUpdateTest extends KernelTestCase
{
    use DispatchedMessagesAssertionTrait;

    private const string ACCOUNT_ID = '9fc62b77-8dc6-4493-b245-6020d30573bd';

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester(new Application(self::bootKernel())->find('app:account:stress-test'));
    }

    #[Test]
    public function dispatchesCreditsAndDebitsWhoseNetSumEqualsAmount(): void
    {
        // when
        $status = $this->tester->execute([
            'account-id' => self::ACCOUNT_ID,
            'amount' => '10000',
            '--iterations' => '25',
            '--debit-ratio' => '40',
        ]);

        // then
        self::assertSame(Command::SUCCESS, $status);
        [$credits, $debits] = $this->dispatchedAmounts();
        self::assertCount(25, $credits);
        self::assertCount(25, $debits);
        self::assertSame(4000, array_sum($debits));
        self::assertSame(14000, array_sum($credits));
        self::assertSame(10000, array_sum($credits) - array_sum($debits));
        self::assertGreaterThan(0, min([...$credits, ...$debits]));
    }

    #[Test]
    public function dispatchesOnlyCreditsWhenDebitRatioIsZero(): void
    {
        // when
        $status = $this->tester->execute([
            'account-id' => self::ACCOUNT_ID,
            'amount' => '1001',
            '--iterations' => '7',
            '--debit-ratio' => '0',
        ]);

        // then
        self::assertSame(Command::SUCCESS, $status);
        [$credits, $debits] = $this->dispatchedAmounts();
        self::assertCount(7, $credits);
        self::assertSame([], $debits);
        self::assertSame(1001, array_sum($credits));
    }

    #[Test]
    public function everyEntryGetsItsOwnId(): void
    {
        // when
        $this->tester->execute(['account-id' => self::ACCOUNT_ID, 'amount' => '1000', '--iterations' => '10']);

        // then
        $ids = [];
        foreach (self::getDispatchedMessages('command.bus') as $dispatched) {
            $message = $dispatched->message;
            self::assertTrue(
                $message instanceof AddAccountEntryCreditCommand || $message instanceof AddAccountEntryDebitCommand,
            );
            self::assertSame(self::ACCOUNT_ID, $message->accountId->toRfc4122());
            self::assertSame('rabbit', $dispatched->transport);
            $ids[] = $message->accountEntryId->toRfc4122();
        }
        self::assertCount(20, array_unique($ids));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'invalid account id' => [['account-id' => 'nope', 'amount' => '1000'], 'Invalid account id "nope".'];
        yield 'uuid v7' => [
            ['account-id' => '01a131a9-79d5-7131-977d-15dd07f09b34', 'amount' => '1000'],
            'Invalid account id',
        ];
        yield 'zero iterations' => [
            ['account-id' => self::ACCOUNT_ID, 'amount' => '1000', '--iterations' => '0'],
            'Iterations must be greater than zero.',
        ];
        yield 'amount below iterations' => [
            ['account-id' => self::ACCOUNT_ID, 'amount' => '5', '--iterations' => '10'],
            'Amount must be at least 10 (one minor unit per iteration).',
        ];
        yield 'negative debit ratio' => [
            ['account-id' => self::ACCOUNT_ID, 'amount' => '1000', '--iterations' => '10', '--debit-ratio' => '-1'],
            'Debit ratio must not be negative.',
        ];
        yield 'debit total below iterations' => [
            ['account-id' => self::ACCOUNT_ID, 'amount' => '100', '--iterations' => '50', '--debit-ratio' => '10'],
            'Debit total 10 is too small for 50 iterations',
        ];
    }

    /**
     * @param array<string, string> $input
     */
    #[Test]
    #[DataProvider('invalidInput')]
    public function rejectsInvalidInputWithoutDispatching(array $input, string $error): void
    {
        // when
        $status = $this->tester->execute($input);

        // then
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString($error, (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()));
        self::assertSame([], self::getDispatchedMessages('command.bus'));
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function dispatchedAmounts(): array
    {
        $credits = [];
        $debits = [];
        foreach (self::getDispatchedMessages('command.bus') as $dispatched) {
            $message = $dispatched->message;
            if ($message instanceof AddAccountEntryCreditCommand) {
                $credits[] = $message->credit->value;
            } elseif ($message instanceof AddAccountEntryDebitCommand) {
                $debits[] = $message->debit->value;
            }
        }

        return [$credits, $debits];
    }
}
