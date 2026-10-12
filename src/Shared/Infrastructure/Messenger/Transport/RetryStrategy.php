<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Transport;

use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Throwable;

final readonly class RetryStrategy implements RetryStrategyInterface
{
    /**
     * @param list<int>                     $delaysMs                waiting time before each consecutive retry
     * @param list<class-string<Throwable>> $alwaysRetriedExceptions retried without limit
     */
    public function __construct(
        private array $delaysMs = [1_000, 2_000, 3_000, 4_000],
        private array $alwaysRetriedExceptions = [
            OptimisticLockException::class,
        ],
    ) {
    }

    public function isRetryable(Envelope $message, ?Throwable $throwable = null): bool
    {
        if ($this->isAlwaysRetried($throwable)) {
            return true;
        }

        return RedeliveryStamp::getRetryCountFromEnvelope($message) < \count($this->delaysMs);
    }

    public function getWaitingTime(Envelope $message, ?Throwable $throwable = null): int
    {
        if ($this->isAlwaysRetried($throwable)) {
            return 1_000;
        }

        $retries = RedeliveryStamp::getRetryCountFromEnvelope($message);

        return $this->delaysMs[min($retries, \count($this->delaysMs) - 1)];
    }

    /**
     * Handler exceptions arrive wrapped in HandlerFailedException, so look through the whole chain.
     */
    private function isAlwaysRetried(?Throwable $throwable): bool
    {
        if (null === $throwable) {
            return false;
        }

        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            foreach ($this->alwaysRetriedExceptions as $class) {
                if ($current instanceof $class) {
                    return true;
                }
            }

            if ($current instanceof HandlerFailedException) {
                foreach ($current->getWrappedExceptions() as $wrapped) {
                    if ($this->isAlwaysRetried($wrapped)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
