<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Bus\Middleware;

use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\UuidV7;

/**
 * Dispatch: stamps the message with the current correlation id (HTTP request, console command or the message being
 * handled). Receive (worker, sync transport): makes the message's correlation id the current one, so the handler's
 * logs and the messages it dispatches carry it.
 *
 * The id is deliberately not restored after handling: messages dispatched with DispatchAfterCurrentBusStamp pass this
 * middleware only once the current message is handled. The worker resets CorrelationIdStorage after every message
 * (ResetServicesListener).
 */
#[WithMonologChannel('messenger')]
readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorrelationIdStorage $correlationIdStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);

        if (null !== $envelope->last(ReceivedStamp::class)) {
            if (null !== $stamp) {
                $this->correlationIdStorage->set($stamp->correlationId);
            } else {
                $correlationId = new UuidV7()->toRfc4122();
                $this->correlationIdStorage->set($correlationId);
                $envelope = $envelope->with(new CorrelationIdStamp($correlationId));
                $this->logger->info('Received message without correlationId, generated a new one.', [
                    'class' => $envelope->getMessage()::class,
                    'correlationId' => $correlationId,
                    'correlationIdSource' => 'generated',
                ]);
            }
        } elseif (null === $stamp) {
            $correlationId = $this->correlationIdStorage->get();
            if (null === $correlationId) {
                $correlationId = new UuidV7()->toRfc4122();
                $this->correlationIdStorage->set($correlationId);
            }
            $envelope = $envelope->with(new CorrelationIdStamp($correlationId));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
