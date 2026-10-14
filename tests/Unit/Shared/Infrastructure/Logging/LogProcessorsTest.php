<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use App\Shared\Infrastructure\Logging\ServiceNameProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogProcessorsTest extends TestCase
{
    #[Test]
    public function addsCurrentCorrelationId(): void
    {
        // given
        $storage = new CorrelationIdStorage();
        $storage->set('0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091');

        // when
        $record = new CorrelationIdProcessor($storage)($this->record());

        // then
        self::assertSame(['correlationId' => '0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091'], $record->extra);
    }

    #[Test]
    public function addsNoCorrelationIdOutsideOfRequest(): void
    {
        // given
        $storage = new CorrelationIdStorage();
        $storage->set('0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091');
        $storage->reset();

        // when
        $record = new CorrelationIdProcessor($storage)($this->record());

        // then
        self::assertSame([], $record->extra);
    }

    #[Test]
    public function addsServiceName(): void
    {
        // when
        $record = new ServiceNameProcessor('symfony8')($this->record());

        // then
        self::assertSame(['service' => 'symfony8'], $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'message');
    }
}
