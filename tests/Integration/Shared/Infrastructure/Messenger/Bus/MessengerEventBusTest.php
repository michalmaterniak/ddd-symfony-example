<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Infrastructure\Messenger\Bus;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\MessageModeOption;
use App\Shared\Infrastructure\Messenger\Bus\EventBus\MessengerEventBus;
use App\Tests\Support\Messenger\DispatchedMessage;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use App\Tests\Support\Messenger\Dummy\DummyCommand;
use App\Tests\Support\Messenger\Dummy\DummyCommandHandler;
use App\Tests\Support\Messenger\Dummy\DummyEvent;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class MessengerEventBusTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchedMessagesAssertionTrait;

    private EventBus $eventBus;

    protected function setUp(): void
    {
        self::mockTime('2026-10-12 10:00:00');
        $this->eventBus = self::getContainer()->get(EventBus::class);
    }

    #[Test]
    public function eventBusInterfaceResolvesToMessengerImplementation(): void
    {
        self::assertInstanceOf(MessengerEventBus::class, $this->eventBus);
    }

    #[Test]
    public function publishesEveryEventToRabbitWhenNoCommandIsBeingHandled(): void
    {
        // given
        $first = new AccountCreated(new AccountId(), new AccountName('First'), new DateTimeImmutable());
        $second = new AccountCreated(new AccountId(), new AccountName('Second'), new DateTimeImmutable());

        // when
        $this->eventBus->publish([$first, $second]);

        // then
        self::assertDispatchedMessages(
            [new DispatchedMessage($first, 'rabbit'), new DispatchedMessage($second, 'rabbit')],
            'event.bus',
        );
    }

    #[Test]
    public function publishingNothingSendsNothing(): void
    {
        // when
        $this->eventBus->publish([]);

        // then
        self::assertDispatchedMessages([], 'event.bus');
    }

    #[Test]
    public function publishesEventsSynchronouslyWhenAsked(): void
    {
        // given
        $event = new DummyEvent('first');

        // when
        $this->eventBus->publish([$event], MessageModeOption::sync());

        // then: sent to the sync transport, which handles it right away on the same bus
        self::assertEquals(
            // dispatched by the caller, then again by the sync transport (with ReceivedStamp) to handle it
            [new DispatchedMessage($event, 'sync'), new DispatchedMessage($event, 'sync')],
            self::getDispatchedMessages('event.bus'),
        );
        self::assertSame([], self::getSentMessages('rabbit'));
    }

    #[Test]
    public function handlerPublishesRecordedEventsToRabbit(): void
    {
        // given: the account was created earlier, by another process
        $accountId = new AccountId();
        self::getContainer()->get(AccountRepository::class)->new(Account::create(new CreateAccountDto(
            $accountId,
            new AccountName('Savings'),
            new AccountDescription('Main account'),
            Clock::get()->now(),
        )));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $entryId = new AccountEntryId();
        $effectiveAt = new DateTimeImmutable('2026-10-01 12:00:00');

        // when: handled the way a worker does it (received from the transport)
        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('command.bus');
        $commandBus->dispatch(new Envelope(
            new AddAccountEntryCreditCommand($entryId, $accountId, new AccountCredit(150), $effectiveAt),
            [new ReceivedStamp('rabbit')],
        ));

        // then
        self::assertDispatchedMessages(
            [
                new DispatchedMessage(
                    new AccountCreditUpdated(
                        $entryId,
                        $accountId,
                        new AccountCredit(150),
                        $effectiveAt,
                        new DateTimeImmutable('2026-10-12 10:00:00'),
                    ),
                    null,
                ),
            ],
            'event.bus',
        );
    }

    #[Test]
    public function eventsPublishedWhileHandlingCommandAreSentOnceItIsHandled(): void
    {
        // given
        $first = new DummyEvent('first');
        $second = new DummyEvent('second');

        // when
        $this->handleAsWorker(new DummyCommand(events: [$first, $second]));

        // then
        self::assertEquals([$first, $second], self::getSentMessages('rabbit'));
    }

    #[Test]
    public function eventsPublishedByFailedCommandAreNotSent(): void
    {
        // given
        $command = new DummyCommand(
            events: [new DummyEvent('first'), new DummyEvent('second')],
            failAfterPublishing: true,
        );

        // when
        try {
            $this->handleAsWorker($command);
            self::fail('Handling the command was expected to fail.');
        } catch (HandlerFailedException $exception) {
            self::assertSame(DummyCommandHandler::FAILURE_MESSAGE, $exception->getPrevious()?->getMessage());
        }

        // then: the events reached the event bus, but were dropped together with the rolled back command
        self::assertCount(2, self::getDispatchedMessages('event.bus'));
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
