<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * How a message dispatched on a bus should be delivered.
 *
 * @see NullMessageOption when the caller has no preference
 */
interface MessageOption
{
    public function mode(): MessageMode;
}
