<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger\Dummy;

use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\EventBus;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Registered only in the test environment (config/services.yaml, when@test).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DummyCommandHandler
{
    public const string FAILURE_MESSAGE = 'Dummy handler failed after publishing messages';

    public function __construct(
        private CommandBus $commandBus,
        private EventBus $eventBus,
    ) {
    }

    public function __invoke(DummyCommand $command): void
    {
        foreach ($command->commands as $nextCommand) {
            $this->commandBus->dispatch($nextCommand);
        }
        $this->eventBus->publish($command->events);

        if ($command->failAfterPublishing) {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        }
    }
}
