<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support\Messenger;

use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Tests\Support\Messenger\DispatchedMessage;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class MessengerAssertionsTraitTest extends KernelTestCase
{
    use DispatchedMessagesAssertionTrait;

    #[Test]
    public function recordsCommandWithTransportOnlyOnCommandBus(): void
    {
        // given
        $command = $this->createAccountCommand();

        // when
        $this->bus('command.bus')->dispatch($command);

        // then
        self::assertDispatchedMessages([new DispatchedMessage($command, 'rabbit')], 'command.bus');
        self::assertDispatchedMessages([], 'event.bus');
        self::assertDispatchedMessages([], 'query.bus');
    }

    #[Test]
    public function recordsMessagesInDispatchOrderWithTransportFromStamp(): void
    {
        // given
        $first = $this->createAccountCommand();
        $second = $this->createAccountCommand();

        // when
        $this->bus('command.bus')->dispatch($first, [new TransportNamesStamp(['doctrine'])]);
        $this->bus('command.bus')->dispatch($second);

        // then
        self::assertDispatchedMessages(
            [
                new DispatchedMessage($first, 'doctrine'),
                new DispatchedMessage($second, 'rabbit'),
            ],
            'command.bus',
        );
    }

    #[Test]
    public function recordsMessageSentToSeveralTransportsOncePerTransport(): void
    {
        // given
        $command = $this->createAccountCommand();

        // when
        $this->bus('command.bus')->dispatch($command, [new TransportNamesStamp(['rabbit', 'doctrine'])]);

        // then
        self::assertDispatchedMessages(
            [
                new DispatchedMessage($command, 'rabbit'),
                new DispatchedMessage($command, 'doctrine'),
            ],
            'command.bus',
        );
    }

    #[Test]
    public function recordsEventsOnEventBusAndUnroutedMessageWithoutTransport(): void
    {
        // given
        $event = new AccountCreated(new AccountId(), new AccountName('Savings'), new DateTimeImmutable('2026-09-01'));
        $unrouted = new stdClass();

        // when
        $this->bus('event.bus')->dispatch($event);
        $this->bus('event.bus')->dispatch($unrouted);

        // then
        self::assertDispatchedMessages(
            [
                new DispatchedMessage($event, 'rabbit'),
                new DispatchedMessage($unrouted, null),
            ],
            'event.bus',
        );
        self::assertDispatchedMessages([], 'command.bus');
    }

    #[Test]
    public function comparesMessagesByValue(): void
    {
        // given
        $accountId = new AccountId();
        $createdAt = new DateTimeImmutable('2026-09-01');
        $this->bus('event.bus')->dispatch(new AccountCreated($accountId, new AccountName('Savings'), $createdAt));

        // then
        self::assertDispatchedMessages(
            [new DispatchedMessage(new AccountCreated($accountId, new AccountName('Savings'), $createdAt), 'rabbit')],
            'event.bus',
        );
    }

    #[Test]
    public function returnsDispatchedMessagesCollection(): void
    {
        // given
        $command = $this->createAccountCommand();
        $this->bus('command.bus')->dispatch($command);

        // when
        $dispatchedMessages = self::getDispatchedMessages('command.bus');

        // then
        self::assertCount(1, $dispatchedMessages);
        self::assertEquals([new DispatchedMessage($command, 'rabbit')], $dispatchedMessages);
    }

    #[Test]
    public function failsWhenTransportDiffers(): void
    {
        // given
        $command = $this->createAccountCommand();
        $this->bus('command.bus')->dispatch($command);

        // then
        $this->expectException(AssertionFailedError::class);

        // when
        self::assertDispatchedMessages([new DispatchedMessage($command, 'doctrine')], 'command.bus');
    }

    #[Test]
    public function failsWhenMessageDiffers(): void
    {
        // given
        $this->bus('command.bus')->dispatch($this->createAccountCommand());

        // then
        $this->expectException(AssertionFailedError::class);

        // when
        self::assertDispatchedMessages([new DispatchedMessage($this->createAccountCommand(), 'rabbit')], 'command.bus');
    }

    #[Test]
    public function failsWhenMessageWasDispatchedOnAnotherBus(): void
    {
        // given
        $command = $this->createAccountCommand();
        $this->bus('command.bus')->dispatch($command);

        // then
        $this->expectException(AssertionFailedError::class);

        // when
        self::assertDispatchedMessages([new DispatchedMessage($command, 'rabbit')], 'event.bus');
    }

    #[Test]
    public function throwsExceptionForUnknownBus(): void
    {
        // then
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bus "unknown.bus" is not traced');

        // when
        self::assertDispatchedMessages([], 'unknown.bus');
    }

    private function bus(string $busName): MessageBusInterface
    {
        $bus = self::getContainer()->get($busName);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function createAccountCommand(): CreateAccountCommand
    {
        return new CreateAccountCommand(
            new AccountId(),
            new AccountName('Savings'),
            new AccountDescription('savings account'),
        );
    }
}
