<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

enum MessageMode: string
{
    /**
     * Handled later by a worker, through the transport the message is routed to (config/packages/messenger.yaml).
     */
    case ASYNC = 'async';

    /**
     * Handled immediately, in the current process; handler failures reach the caller.
     */
    case SYNC = 'sync';
}
