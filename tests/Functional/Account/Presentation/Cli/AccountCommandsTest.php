<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account\Presentation\Cli;

use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class AccountCommandsTest extends KernelTestCase
{
    use DispatchedMessagesAssertionTrait;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester(new Application(self::bootKernel())->find('app:account:create'));
    }

    #[Test]
    public function createsAccountAsynchronously(): void
    {
        // when
        $status = $this->tester->execute(['name' => 'Savings', 'description' => 'savings account']);

        // then
        self::assertSame(Command::SUCCESS, $status);

        $messages = self::getDispatchedMessages('command.bus');
        self::assertSame('sync', $messages[0]->transport);
        $command = $messages[0]->message;
        self::assertInstanceOf(CreateAccountCommand::class, $command);
        self::assertSame('Savings', $command->accountName->name);
        self::assertSame('savings account', $command->accountDescription->value);
        self::assertInstanceOf(Account::class, self::getContainer()->get(AccountRepository::class)->findById(
            $command->accountId,
        ));
        self::assertStringContainsString(
            \sprintf('Created account "Savings" with id %s.', $command->accountId->toRfc4122()),
            $this->display(),
        );
    }

    #[Test]
    public function failsForTakenAccountNameInsteadOfReportingSuccess(): void
    {
        // given
        $this->tester->execute(['name' => 'Savings', 'description' => 'savings account']);

        // then
        $this->expectException(HandlerFailedException::class);

        // when
        $this->tester->execute(['name' => 'Savings', 'description' => 'another account']);
    }

    #[Test]
    public function trimsAccountName(): void
    {
        // when
        $this->tester->execute(['name' => '  Savings  ', 'description' => '']);

        // then
        $command = self::getDispatchedMessages('command.bus')[0]->message;
        self::assertInstanceOf(CreateAccountCommand::class, $command);
        self::assertSame('Savings', $command->accountName->name);
    }

    #[Test]
    public function rejectsBlankAccountName(): void
    {
        // when
        $status = $this->tester->execute(['name' => '   ', 'description' => 'savings account']);

        // then
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Account name must not be empty.', $this->display());
        self::assertSame([], self::getDispatchedMessages('command.bus'));
    }

    private function display(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay());
    }
}
