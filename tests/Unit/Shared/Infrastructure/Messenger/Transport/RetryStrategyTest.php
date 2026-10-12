<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger\Transport;

use App\Shared\Infrastructure\Messenger\Transport\RetryStrategy;
use Doctrine\ORM\OptimisticLockException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class RetryStrategyTest extends TestCase
{
    private RetryStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new RetryStrategy();
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function retryCounts(): iterable
    {
        yield 'first failure' => [0, true];
        yield 'third retry' => [3, true];
        yield 'limit reached' => [4, false];
        yield 'over limit' => [10, false];
    }

    #[DataProvider('retryCounts')]
    public function testRetriesRegularExceptionUpToConfiguredLimit(int $retryCount, bool $expected): void
    {
        self::assertSame(
            $expected,
            $this->strategy->isRetryable($this->envelope($retryCount), new RuntimeException()),
        );
    }

    public function testRetriesWhenThrowableIsUnknown(): void
    {
        self::assertTrue($this->strategy->isRetryable($this->envelope(0)));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function waitingTimes(): iterable
    {
        yield 'first failure' => [0, 1_000];
        yield 'second' => [1, 2_000];
        yield 'third' => [2, 3_000];
        yield 'fourth' => [3, 4_000];
        yield 'over limit uses last delay' => [9, 4_000];
    }

    #[DataProvider('waitingTimes')]
    public function testWaitingTimeFollowsConfiguredDelays(int $retryCount, int $expected): void
    {
        self::assertSame(
            $expected,
            $this->strategy->getWaitingTime($this->envelope($retryCount), new RuntimeException()),
        );
    }

    public function testOptimisticLockIsAlwaysRetried(): void
    {
        $exception = new OptimisticLockException('locked', null);

        self::assertTrue($this->strategy->isRetryable($this->envelope(100), $exception));
        self::assertSame(1_000, $this->strategy->getWaitingTime($this->envelope(100), $exception));
    }

    public function testFindsAlwaysRetriedExceptionInPreviousChain(): void
    {
        $exception = new RuntimeException('outer', 0, new OptimisticLockException('locked', null));

        self::assertTrue($this->strategy->isRetryable($this->envelope(100), $exception));
    }

    public function testFindsAlwaysRetriedExceptionWrappedByHandlerFailedException(): void
    {
        $envelope = $this->envelope(100);
        $exception = new HandlerFailedException($envelope, [new OptimisticLockException('locked', null)]);

        self::assertTrue($this->strategy->isRetryable($envelope, $exception));
    }

    public function testHandlerFailedExceptionWithRegularExceptionRespectsLimit(): void
    {
        $envelope = $this->envelope(4);
        $exception = new HandlerFailedException($envelope, [new RuntimeException()]);

        self::assertFalse($this->strategy->isRetryable($envelope, $exception));
    }

    public function testCustomDelaysAndExceptionsAreUsed(): void
    {
        $strategy = new RetryStrategy([10, 20], [RuntimeException::class]);

        self::assertSame(20, $strategy->getWaitingTime($this->envelope(1), new LogicException()));
        self::assertFalse($strategy->isRetryable($this->envelope(2), new LogicException()));
        self::assertTrue($strategy->isRetryable($this->envelope(2), new RuntimeException()));
    }

    private function envelope(int $retryCount): Envelope
    {
        $envelope = new Envelope(new stdClass());

        return 0 === $retryCount ? $envelope : $envelope->with(new RedeliveryStamp($retryCount));
    }
}
