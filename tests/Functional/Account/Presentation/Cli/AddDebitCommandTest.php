<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account\Presentation\Cli;

use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AddDebitCommandTest extends KernelTestCase
{
    use DispatchedMessagesAssertionTrait;

    private const string ACCOUNT_ID = '9fc62b77-8dc6-4493-b245-6020d30573bd';

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester(new Application(self::bootKernel())->find('app:account:add-debit'));
        self::getContainer()->get(AccountRepository::class)->new(Account::create(new CreateAccountDto(
            new AccountId(self::ACCOUNT_ID),
            new AccountName('Savings'),
            new AccountDescription('savings account'),
            new DateTimeImmutable(),
        )));
    }

    #[Test]
    public function queuesDebitEntryAsynchronously(): void
    {
        // when
        $status = $this->tester->execute([
            'account-id' => self::ACCOUNT_ID,
            'debit' => '150',
            '--effective-at' => '2026-10-01 12:00:00',
        ]);

        // then
        self::assertSame(Command::SUCCESS, $status);

        $messages = self::getDispatchedMessages('command.bus');
        self::assertCount(1, $messages);
        self::assertSame('rabbit', $messages[0]->transport);
        $command = $messages[0]->message;
        self::assertInstanceOf(AddAccountEntryDebitCommand::class, $command);
        self::assertSame(self::ACCOUNT_ID, $command->accountId->toRfc4122());
        self::assertSame(150, $command->debit->value);
        self::assertEquals(new DateTimeImmutable('2026-10-01 12:00:00'), $command->effectiveAt);

        // handled later by a worker
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM account_entry'));
        self::assertStringContainsString(
            'Ordered debit of 150 to account '.self::ACCOUNT_ID,
            (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()),
        );
    }

    #[Test]
    public function eachRunQueuesNewEntry(): void
    {
        // when
        $this->tester->execute(['account-id' => self::ACCOUNT_ID, 'debit' => '150']);
        $this->tester->execute(['account-id' => self::ACCOUNT_ID, 'debit' => '150']);

        // then
        $sent = self::getSentMessages('rabbit');
        self::assertCount(2, $sent);
        self::assertInstanceOf(AddAccountEntryDebitCommand::class, $sent[0]);
        self::assertInstanceOf(AddAccountEntryDebitCommand::class, $sent[1]);
        self::assertNotEquals($sent[0]->accountEntryId, $sent[1]->accountEntryId);
    }

    #[Test]
    public function effectiveAtDefaultsToNow(): void
    {
        // given
        $before = new DateTimeImmutable();

        // when
        $this->tester->execute(['account-id' => self::ACCOUNT_ID, 'debit' => '150']);

        // then
        $command = self::getDispatchedMessages('command.bus')[0]->message;
        self::assertInstanceOf(AddAccountEntryDebitCommand::class, $command);
        self::assertGreaterThanOrEqual($before, $command->effectiveAt);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $command->effectiveAt);
    }

    #[Test]
    public function queuesEntryForUnknownAccountLeavingTheCheckToWorker(): void
    {
        // when
        $status = $this->tester->execute(['account-id' => new AccountId()->toRfc4122(), 'debit' => '150']);

        // then
        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, self::getSentMessages('rabbit'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'not a uuid' => ['nope', '150', 'Invalid account id "nope".'];
        yield 'uuid v7' => ['01a131a9-79d5-7131-977d-15dd07f09b34', '150', 'Invalid account id'];
        yield 'zero amount' => [self::ACCOUNT_ID, '0', 'Debit must be greater than zero.'];
        yield 'negative amount' => [self::ACCOUNT_ID, '-5', 'Debit must be greater than zero.'];
    }

    #[Test]
    #[DataProvider('invalidInput')]
    public function rejectsInvalidInputWithoutDispatching(string $accountId, string $amount, string $error): void
    {
        // when
        $status = $this->tester->execute(['account-id' => $accountId, 'debit' => $amount]);

        // then
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString($error, (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()));
        self::assertSame([], self::getDispatchedMessages('command.bus'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
