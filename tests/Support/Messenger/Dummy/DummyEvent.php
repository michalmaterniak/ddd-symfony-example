<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger\Dummy;

use App\Shared\Domain\DomainEvent;

final readonly class DummyEvent implements DomainEvent
{
    public function __construct(
        public string $name,
    ) {
    }
}
