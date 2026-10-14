<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Shared\Infrastructure\Messenger\Bus\Middleware\CorrelationIdMiddleware;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Travels with the message through the transport (X-Message-Stamp-* header), see {@see CorrelationIdMiddleware}.
 */
readonly class CorrelationIdStamp implements StampInterface
{
    public function __construct(
        public string $correlationId,
    ) {
    }
}
