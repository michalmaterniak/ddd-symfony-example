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
use Symfony\Component\Messenger\Exception\HandlerFailedException;

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
    public function addsDebitEntrySynchronously(): void
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
        self::assertSame('sync', $messages[0]->transport);
        $command = $messages[0]->message;
        self::assertInstanceOf(AddAccountEntryDebitCommand::class, $command);
        self::assertSame(self::ACCOUNT_ID, $command->accountId->toRfc4122());
        self::assertSame(150, $command->debit->value);
        self::assertEquals(new DateTimeImmutable('2026-10-01 12:00:00'), $command->effectiveAt);

        self::assertSame(
            ['amount' => 150, 'side' => 'debit'],
            $this->connection()->fetchAssociative(
                'SELECT amount, side FROM account_entry WHERE id = :id',
                ['id' => $command->accountEntryId->toRfc4122()],
            ),
        );
        self::assertStringContainsString(
            'Added debit of 150 to account '.self::ACCOUNT_ID,
            (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()),
        );
    }

    #[Test]
    public function eachRunAddsNewEntry(): void
    {
        // when
        $this->tester->execute(['account-id' => self::ACCOUNT_ID, 'debit' => '150']);
        $this->tester->execute(['account-id' => self::ACCOUNT_ID, 'debit' => '150']);

        // then
        self::assertSame(2, $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM account_entry WHERE account_id = :id',
            ['id' => self::ACCOUNT_ID],
        ));
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
    public function failsForUnknownAccountInsteadOfReportingSuccess(): void
    {
        // then
        $this->expectException(HandlerFailedException::class);
        $this->expectExceptionMessage('Account not found');

        // when
        $this->tester->execute(['account-id' => new AccountId()->toRfc4122(), 'debit' => '150']);
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
