<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Bus\CommandBus;

use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\MessageOption;
use App\Shared\Application\Bus\NullMessageOption;
use App\Shared\Application\Command;
use App\Shared\Infrastructure\Messenger\Bus\MessageOptionStamps;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerCommandBus implements CommandBus
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function dispatch(Command $command, MessageOption $option = new NullMessageOption()): void
    {
        $this->commandBus->dispatch(new Envelope($command, MessageOptionStamps::for($option)));
    }
}
