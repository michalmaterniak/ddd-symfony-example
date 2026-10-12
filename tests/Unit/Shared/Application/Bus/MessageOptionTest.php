<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Bus;

use App\Shared\Application\Bus\MessageMode;
use App\Shared\Application\Bus\MessageModeOption;
use App\Shared\Application\Bus\NullMessageOption;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageOptionTest extends TestCase
{
    #[Test]
    public function nullOptionDefaultsToAsync(): void
    {
        self::assertSame(MessageMode::ASYNC, new NullMessageOption()->mode());
    }

    #[Test]
    public function modeOptionKeepsTheChosenMode(): void
    {
        self::assertSame(MessageMode::SYNC, MessageModeOption::sync()->mode());
        self::assertSame(MessageMode::ASYNC, MessageModeOption::async()->mode());
        self::assertSame(MessageMode::SYNC, new MessageModeOption(MessageMode::SYNC)->mode());
    }
}
