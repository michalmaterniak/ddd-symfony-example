<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\EventListener;

use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\UuidV7;

#[AsEventListener(event: ConsoleEvents::COMMAND, priority: 1024)]
readonly class ConsoleCorrelationIdListener
{
    private const array MESSAGE_HANDLING_COMMANDS = ['messenger:consume', 'messenger:failed:retry'];

    public function __construct(
        private CorrelationIdStorage $correlationIdStorage,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        if (
            \in_array($event->getCommand()?->getName(), self::MESSAGE_HANDLING_COMMANDS, true)
            || null !== $this->correlationIdStorage->get()
        ) {
            return;
        }

        $this->correlationIdStorage->set(new UuidV7()->toRfc4122());
    }
}
