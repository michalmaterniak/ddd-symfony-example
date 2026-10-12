<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

final readonly class MessageModeOption implements MessageOption
{
    public function __construct(
        private MessageMode $mode,
    ) {
    }

    public static function sync(): self
    {
        return new self(MessageMode::SYNC);
    }

    public static function async(): self
    {
        return new self(MessageMode::ASYNC);
    }

    public function mode(): MessageMode
    {
        return $this->mode;
    }
}
