<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger\Bus\EventBus\Middleware;

use App\Shared\Infrastructure\Messenger\Bus\EventBus\Middleware\DefaultDelayMiddleware;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class DefaultDelayMiddlewareTest extends TestCase
{
    public function testAddsDefaultDelayToNewMessage(): void
    {
        $result = new DefaultDelayMiddleware()->handle(new Envelope(new stdClass()), $this->stack());

        self::assertSame(2_000, $result->last(DelayStamp::class)?->getDelay());
    }

    public function testUsesConfiguredDelay(): void
    {
        $result = new DefaultDelayMiddleware(500)->handle(new Envelope(new stdClass()), $this->stack());

        self::assertSame(500, $result->last(DelayStamp::class)?->getDelay());
    }

    public function testKeepsExplicitDelay(): void
    {
        $envelope = new Envelope(new stdClass(), [new DelayStamp(10)]);

        $result = new DefaultDelayMiddleware()->handle($envelope, $this->stack());

        self::assertCount(1, $result->all(DelayStamp::class));
        self::assertSame(10, $result->last(DelayStamp::class)?->getDelay());
    }

    public function testDoesNotDelayReceivedMessage(): void
    {
        $envelope = new Envelope(new stdClass(), [new ReceivedStamp('rabbit')]);

        $result = new DefaultDelayMiddleware()->handle($envelope, $this->stack());

        self::assertNull($result->last(DelayStamp::class));
    }

    private function stack(): StackInterface
    {
        return new StackMiddleware();
    }
}
