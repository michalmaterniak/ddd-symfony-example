<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

final readonly class DispatchedMessage
{
    public function __construct(
        public object $message,
        public ?string $transport,
    ) {
    }
}
