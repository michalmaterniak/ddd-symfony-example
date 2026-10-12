<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Infrastructure\Messenger\Bus;

use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\MessageModeOption;
use App\Shared\Infrastructure\Messenger\Bus\CommandBus\MessengerCommandBus;
use App\Tests\Support\Messenger\DispatchedMessage;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use App\Tests\Support\Messenger\Dummy\DummyCommand;
use App\Tests\Support\Messenger\Dummy\DummyCommandHandler;
use App\Tests\Support\Messenger\Dummy\DummyEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class MessengerCommandBusTest extends KernelTestCase
{
    use DispatchedMessagesAssertionTrait;

    private CommandBus $commandBus;

    protected function setUp(): void
    {
        $this->commandBus = self::getContainer()->get(CommandBus::class);
    }

    #[Test]
    public function commandBusInterfaceResolvesToMessengerImplementation(): void
    {
        self::assertInstanceOf(MessengerCommandBus::class, $this->commandBus);
    }

    #[Test]
    public function dispatchesCommandOnCommandBusRoutedToRabbit(): void
    {
        // given
        $command = new CreateAccountCommand(
            accountId: new AccountId(),
            accountName: new AccountName('Savings'),
            accountDescription: new AccountDescription('Main account'),
        );

        // when
        $this->commandBus->dispatch($command);

        // then
        self::assertDispatchedMessages([new DispatchedMessage($command, 'rabbit')], 'command.bus');
        self::assertSame([], self::getDispatchedMessages('event.bus'));
    }

    #[Test]
    public function handlesCommandSynchronouslyWhenAsked(): void
    {
        // given
        $event = new DummyEvent('handled');
        $command = new DummyCommand(events: [$event]);

        // when
        $this->commandBus->dispatch($command, MessageModeOption::sync());

        // then: handled right away through the sync transport; only the event it published (async) reaches rabbit
        self::assertEquals(
            // dispatched by the caller, then again by the sync transport (with ReceivedStamp) to handle it
            [new DispatchedMessage($command, 'sync'), new DispatchedMessage($command, 'sync')],
            self::getDispatchedMessages('command.bus'),
        );
        self::assertEquals([$event], self::getSentMessages('rabbit'));
    }

    #[Test]
    public function syncCommandFailureReachesTheCaller(): void
    {
        // then
        $this->expectException(HandlerFailedException::class);
        $this->expectExceptionMessage(DummyCommandHandler::FAILURE_MESSAGE);

        // when
        $this->commandBus->dispatch(new DummyCommand(failAfterPublishing: true), MessageModeOption::sync());
    }

    #[Test]
    public function commandsDispatchedWhileHandlingCommandAreSentOnceItIsHandled(): void
    {
        // given
        $first = new DummyCommand();
        $second = new DummyCommand();

        // when
        $this->handleAsWorker(new DummyCommand(commands: [$first, $second]));

        // then
        self::assertSame([$first, $second], self::getSentMessages('rabbit'));
    }

    #[Test]
    public function commandsDispatchedByFailedCommandAreNotSent(): void
    {
        // given
        $command = new DummyCommand(commands: [new DummyCommand(), new DummyCommand()], failAfterPublishing: true);

        // when
        try {
            $this->handleAsWorker($command);
            self::fail('Handling the command was expected to fail.');
        } catch (HandlerFailedException $exception) {
            self::assertSame(DummyCommandHandler::FAILURE_MESSAGE, $exception->getPrevious()?->getMessage());
        }

        // then: the commands reached the command bus, but were dropped together with the rolled back command
        self::assertCount(3, self::getDispatchedMessages('command.bus'));
        self::assertSame([], self::getSentMessages('rabbit'));
    }

    /**
     * Handles the command the way a worker does it: received from the transport, so it is not sent again.
     */
    private function handleAsWorker(object $command): void
    {
        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('command.bus');
        $commandBus->dispatch(new Envelope($command, [new ReceivedStamp('rabbit')]));
    }
}
