<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Infrastructure\Messenger\Bus\Middleware;

use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\MessageModeOption;
use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use App\Tests\Support\Messenger\Dummy\DummyCommand;
use App\Tests\Support\Messenger\Dummy\DummyEvent;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdMiddlewareTest extends KernelTestCase
{
    private const string CORRELATION_ID = '0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091';
    private const string OTHER_CORRELATION_ID = '0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8092';

    private CorrelationIdStorage $storage;

    protected function setUp(): void
    {
        $this->storage = self::getContainer()->get(CorrelationIdStorage::class);
    }

    #[Test]
    public function stampsDispatchedMessageWithCurrentCorrelationId(): void
    {
        // given
        $this->storage->set(self::CORRELATION_ID);

        // when
        self::getContainer()->get(CommandBus::class)->dispatch(new DummyCommand());

        // then
        self::assertSame([self::CORRELATION_ID], $this->sentCorrelationIds());
    }

    #[Test]
    public function generatesCorrelationIdForDispatchOutsideOfRequestOrCommand(): void
    {
        // when
        self::getContainer()->get(CommandBus::class)->dispatch(new DummyCommand());

        // then
        $correlationIds = $this->sentCorrelationIds();
        self::assertCount(1, $correlationIds);
        self::assertTrue(Uuid::isValid((string) $correlationIds[0]));
        self::assertSame($correlationIds[0], $this->storage->get());
    }

    #[Test]
    public function keepsCorrelationIdAlreadyStampedOnMessage(): void
    {
        // given
        $this->storage->set(self::CORRELATION_ID);

        // when
        $this->commandBus()->dispatch(new Envelope(new DummyCommand(), [
            new CorrelationIdStamp(self::OTHER_CORRELATION_ID),
        ]));

        // then
        self::assertSame([self::OTHER_CORRELATION_ID], $this->sentCorrelationIds());
    }

    #[Test]
    public function messagesDispatchedWhileHandlingCarryHandledMessageCorrelationId(): void
    {
        // given: the worker has just been reset, a message with its own correlation id arrives
        $command = new DummyCommand(
            events: [new DummyEvent('first'), new DummyEvent('second')],
            commands: [new DummyCommand()],
        );

        // when
        $this->commandBus()->dispatch(new Envelope($command, [
            new ReceivedStamp('rabbit'),
            new CorrelationIdStamp(self::CORRELATION_ID),
        ]));

        // then: the next command and the events (sent only after the command was handled) carry it on
        self::assertSame(
            [self::CORRELATION_ID, self::CORRELATION_ID, self::CORRELATION_ID],
            $this->sentCorrelationIds(),
        );
        self::assertSame(self::CORRELATION_ID, $this->storage->get());
    }

    #[Test]
    public function receivedMessageCorrelationIdReplacesCurrentOne(): void
    {
        // given
        $this->storage->set(self::OTHER_CORRELATION_ID);

        // when
        $this->commandBus()->dispatch(new Envelope(new DummyCommand(events: [new DummyEvent('first')]), [
            new ReceivedStamp('rabbit'),
            new CorrelationIdStamp(self::CORRELATION_ID),
        ]));

        // then
        self::assertSame([self::CORRELATION_ID], $this->sentCorrelationIds());
    }

    #[Test]
    public function generatesAndLogsCorrelationIdForReceivedMessageWithoutOne(): void
    {
        // when
        $this->commandBus()->dispatch(new Envelope(new DummyCommand(events: [new DummyEvent('first')]), [
            new ReceivedStamp('rabbit'),
        ]));

        // then
        $correlationId = $this->storage->get();
        self::assertNotNull($correlationId);
        self::assertTrue(Uuid::isValid($correlationId));
        self::assertSame([$correlationId], $this->sentCorrelationIds());

        // defined only in when@test, PHPStan reads the dev container
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $handler = self::getContainer()->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);
        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => 'Received message without correlationId, generated a new one.'
                === $record->message,
        ));
        self::assertCount(1, $records);
        self::assertSame('messenger', $records[0]->channel);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame(DummyCommand::class, $records[0]->context['class']);
        self::assertSame($correlationId, $records[0]->context['correlationId']);
        self::assertSame($correlationId, $records[0]->extra['correlationId']);
    }

    #[Test]
    public function synchronousDispatchKeepsCorrelationId(): void
    {
        // given
        $this->storage->set(self::CORRELATION_ID);

        // when
        self::getContainer()->get(CommandBus::class)->dispatch(
            new DummyCommand(events: [new DummyEvent('first')]),
            MessageModeOption::sync(),
        );

        // then: the event published by the handler (run through the sync transport) is sent to rabbit with it
        self::assertSame([self::CORRELATION_ID], $this->sentCorrelationIds());
        self::assertSame(self::CORRELATION_ID, $this->storage->get());
    }

    #[Test]
    public function correlationIdIsClearedWhenServicesAreReset(): void
    {
        // given
        $this->storage->set(self::CORRELATION_ID);
        // when: what the worker does after every message (ResetServicesListener)
        self::getContainer()->get('services_resetter')->reset();

        // then
        self::assertNull($this->storage->get());
    }

    private function commandBus(): MessageBusInterface
    {
        return self::getContainer()->get('command.bus');
    }

    /**
     * @return list<string|null>
     */
    private function sentCorrelationIds(): array
    {
        $transport = self::getContainer()->get('messenger.transport.rabbit');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(
            static fn (Envelope $envelope): ?string => $envelope->last(CorrelationIdStamp::class)?->correlationId,
            [...$transport->getSent()],
        ));
    }
}
