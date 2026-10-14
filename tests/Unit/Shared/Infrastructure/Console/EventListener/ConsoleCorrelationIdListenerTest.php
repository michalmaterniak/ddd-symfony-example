<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Console\EventListener;

use App\Shared\Infrastructure\Console\EventListener\ConsoleCorrelationIdListener;
use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Uid\Uuid;

final class ConsoleCorrelationIdListenerTest extends TestCase
{
    #[Test]
    public function givesCommandRunNewCorrelationId(): void
    {
        // given
        $storage = new CorrelationIdStorage();

        // when
        new ConsoleCorrelationIdListener($storage)($this->event('app:account:create'));

        // then
        self::assertTrue(Uuid::isValid((string) $storage->get()));
    }

    #[Test]
    public function keepsCorrelationIdOfOuterCommand(): void
    {
        // given
        $storage = new CorrelationIdStorage();
        $storage->set('0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091');

        // when
        new ConsoleCorrelationIdListener($storage)($this->event('app:account:create'));

        // then
        self::assertSame('0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091', $storage->get());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function messageHandlingCommands(): iterable
    {
        yield 'worker' => ['messenger:consume'];
        yield 'failed message retry' => ['messenger:failed:retry'];
    }

    #[Test]
    #[DataProvider('messageHandlingCommands')]
    public function leavesCorrelationIdToMessagesInCommandsHandlingThem(string $commandName): void
    {
        // given
        $storage = new CorrelationIdStorage();

        // when
        new ConsoleCorrelationIdListener($storage)($this->event($commandName));

        // then
        self::assertNull($storage->get());
    }

    private function event(string $commandName): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($commandName), new ArrayInput([]), new NullOutput());
    }
}
