<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Bus\EventBus\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

readonly class DefaultDelayMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $delayMs = 2_000,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class) && null === $envelope->last(DelayStamp::class)) {
            $envelope = $envelope->with(new DelayStamp($this->delayMs));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
