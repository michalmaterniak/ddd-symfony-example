<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger\Bus;

use App\Shared\Application\Bus\MessageModeOption;
use App\Shared\Application\Bus\NullMessageOption;
use App\Shared\Infrastructure\Messenger\Bus\MessageOptionStamps;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class MessageOptionStampsTest extends TestCase
{
    #[Test]
    public function asyncFollowsRoutingAndWaitsForTheCurrentMessage(): void
    {
        self::assertEquals([new DispatchAfterCurrentBusStamp()], MessageOptionStamps::for(MessageModeOption::async()));
    }

    #[Test]
    public function nullOptionIsTreatedAsAsync(): void
    {
        self::assertEquals([new DispatchAfterCurrentBusStamp()], MessageOptionStamps::for(new NullMessageOption()));
    }

    #[Test]
    public function syncOverridesRoutingWithSyncTransport(): void
    {
        self::assertEquals([new TransportNamesStamp(['sync'])], MessageOptionStamps::for(MessageModeOption::sync()));
    }
}
