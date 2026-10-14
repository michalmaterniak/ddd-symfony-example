<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Correlation id of the unit of work being processed (HTTP request), added to every log record
 * by {@see CorrelationIdProcessor}. Reset between requests by the kernel.reset mechanism.
 */
class CorrelationIdStorage implements ResetInterface
{
    private ?string $correlationId = null;

    public function get(): ?string
    {
        return $this->correlationId;
    }

    public function set(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function reset(): void
    {
        $this->correlationId = null;
    }
}
