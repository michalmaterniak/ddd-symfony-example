<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Bus;

use App\Shared\Application\Bus\MessageMode;
use App\Shared\Application\Bus\MessageOption;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Translates a {@see MessageOption} into Messenger stamps.
 */
final class MessageOptionStamps
{
    /**
     * Name of the sync:// transport in config/packages/messenger.yaml.
     */
    public const string SYNC_TRANSPORT = 'sync';

    /**
     * ASYNC follows the routing and, when dispatched while another message is handled, waits until that one is
     * handled (after its transaction is committed). SYNC overrides the routing with the sync transport and runs
     * right away, inside the current transaction if there is one.
     *
     * @return list<StampInterface>
     */
    public static function for(MessageOption $option): array
    {
        return match ($option->mode()) {
            MessageMode::ASYNC => [new DispatchAfterCurrentBusStamp()],
            MessageMode::SYNC => [new TransportNamesStamp([self::SYNC_TRANSPORT])],
        };
    }
}
