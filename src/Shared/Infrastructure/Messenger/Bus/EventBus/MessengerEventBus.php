<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Bus\EventBus;

use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\MessageOption;
use App\Shared\Application\Bus\NullMessageOption;
use App\Shared\Infrastructure\Messenger\Bus\MessageOptionStamps;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerEventBus implements EventBus
{
    public function __construct(
        private MessageBusInterface $eventBus,
    ) {
    }

    public function publish(array $events, MessageOption $option = new NullMessageOption()): void
    {
        foreach ($events as $event) {
            $this->eventBus->dispatch(new Envelope($event, MessageOptionStamps::for($option)));
        }
    }
}
