<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Application\Message;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Account\Application\Command\CreateAccountCommand;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Event\AccountDebitUpdated;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Messages travel through RabbitMQ as JSON, so each one has to survive the transport serializer unchanged.
 */
final class MessageSerializationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{object}>
     */
    public static function messages(): iterable
    {
        $accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');
        $entryId = new AccountEntryId('0b8f4d34-7a3c-4f0e-9f53-3f0f6b5f2a11');
        $effectiveAt = new DateTimeImmutable('2026-10-01T12:00:00+02:00');
        $occurredAt = new DateTimeImmutable('2026-10-12T10:00:00+02:00');

        yield 'CreateAccountCommand' => [
            new CreateAccountCommand($accountId, new AccountName('Savings'), new AccountDescription('savings')),
        ];
        yield 'AddAccountEntryCreditCommand' => [
            new AddAccountEntryCreditCommand($entryId, $accountId, new AccountCredit(150), $effectiveAt),
        ];
        yield 'AddAccountEntryDebitCommand' => [
            new AddAccountEntryDebitCommand($entryId, $accountId, new AccountDebit(70), $effectiveAt),
        ];
        yield 'AccountCreated' => [new AccountCreated($accountId, new AccountName('Savings'), $occurredAt)];
        yield 'AccountCreditUpdated' => [
            new AccountCreditUpdated(
                $entryId,
                $accountId,
                new AccountCredit(150),
                $effectiveAt,
                $occurredAt,
            ),
        ];
        yield 'AccountDebitUpdated' => [
            new AccountDebitUpdated(
                $entryId,
                $accountId,
                new AccountDebit(70),
                $effectiveAt,
                $occurredAt,
            ),
        ];
    }

    #[Test]
    #[DataProvider('messages')]
    public function survivesTransportSerialization(object $message): void
    {
        // given
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        // when
        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        // then
        self::assertEquals($message, $decoded);
    }
}
