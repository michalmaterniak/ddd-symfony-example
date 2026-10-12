<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

use App\Shared\Domain\DomainEvent;

interface EventBus
{
    /**
     * @param list<DomainEvent> $events
     */
    public function publish(array $events, MessageOption $option = new NullMessageOption()): void;
}
