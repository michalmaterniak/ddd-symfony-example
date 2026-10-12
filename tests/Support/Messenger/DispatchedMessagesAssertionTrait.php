<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @mixin KernelTestCase
 *
 * @phpstan-require-extends KernelTestCase
 */
trait DispatchedMessagesAssertionTrait
{
    /**
     * @param list<DispatchedMessage> $expected
     */
    public static function assertDispatchedMessages(array $expected, string $busName, string $message = ''): void
    {
        $dispatchedMessages = self::getDispatchedMessages($busName);
        self::assertCount(
            \count($expected),
            $dispatchedMessages,
            \sprintf('Messages dispatched on "%s" do not match.', $busName),
        );
        self::assertEquals(
            $expected,
            self::getDispatchedMessages($busName),
            '' !== $message ? $message : \sprintf('Messages dispatched on "%s" do not match.', $busName),
        );
    }

    /**
     * @return list<DispatchedMessage>
     */
    public static function getDispatchedMessages(string $busName): array
    {
        $dispatchedMessages = [];
        foreach (self::getTracedBus($busName)->getDispatchedMessages() as $dispatched) {
            $transports = [];
            foreach ($dispatched['stamps_after_dispatch'] as $stamp) {
                if ($stamp instanceof SentStamp) {
                    $transports[] = $stamp->getSenderAlias() ?? $stamp->getSenderClass();
                }
            }

            if ([] === $transports) {
                $dispatchedMessages[] = new DispatchedMessage($dispatched['message'], null);
            }

            foreach ($transports as $transport) {
                $dispatchedMessages[] = new DispatchedMessage($dispatched['message'], $transport);
            }
        }

        return $dispatchedMessages;
    }

    /**
     * Messages actually sent to an in-memory transport. Unlike {@see getDispatchedMessages()} this also sees
     * messages whose sending was deferred with DispatchAfterCurrentBusStamp, and only those that were sent.
     *
     * @return list<object>
     */
    public static function getSentMessages(string $transportName): array
    {
        $transport = self::getContainer()->get('messenger.transport.'.$transportName);
        if (!$transport instanceof InMemoryTransport) {
            throw new InvalidArgumentException(\sprintf(
                'Transport "%s" must be an in-memory transport in the test environment.',
                $transportName,
            ));
        }

        return array_values(array_map(
            static fn (Envelope $envelope): object => $envelope->getMessage(),
            [...$transport->getSent()],
        ));
    }

    public static function resetDispatchedMessages(string $busName): void
    {
        self::getTracedBus($busName)->reset();
    }

    private static function getTracedBus(string $busName): TraceableMessageBus
    {
        $serviceId = 'test.messenger.traced.'.$busName;
        if (!self::getContainer()->has($serviceId)) {
            throw new InvalidArgumentException(\sprintf(
                'Bus "%s" is not traced in the test environment (missing service "%s").',
                $busName,
                $serviceId,
            ));
        }

        $bus = self::getContainer()->get($serviceId);
        if (!$bus instanceof TraceableMessageBus) {
            throw new InvalidArgumentException(\sprintf(
                'Service "%s" must be a "%s".',
                $serviceId,
                TraceableMessageBus::class,
            ));
        }

        return $bus;
    }
}
