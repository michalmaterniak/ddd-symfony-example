<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger\Dummy;

use App\Shared\Application\Command;
use App\Shared\Domain\DomainEvent;

/**
 * Test-only command: its handler dispatches the given commands, publishes the given events
 * and optionally fails afterwards.
 *
 * @see DummyCommandHandler
 */
final readonly class DummyCommand implements Command
{
    /**
     * @param list<DomainEvent> $events
     * @param list<Command>     $commands
     */
    public function __construct(
        public array $events = [],
        public array $commands = [],
        public bool $failAfterPublishing = false,
    ) {
    }
}
