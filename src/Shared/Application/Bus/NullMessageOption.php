<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * No preference given by the caller: the message is delivered the default way, asynchronously.
 */
final readonly class NullMessageOption implements MessageOption
{
    public function mode(): MessageMode
    {
        return MessageMode::ASYNC;
    }
}
