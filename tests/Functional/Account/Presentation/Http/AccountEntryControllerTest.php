<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account\Presentation\Http;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AccountEntryControllerTest extends WebTestCase
{
    use DispatchedMessagesAssertionTrait;

    private const string ACCOUNT_ID = '9fc62b77-8dc6-4493-b245-6020d30573bd';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(AccountRepository::class)->new(Account::create(new CreateAccountDto(
            new AccountId(self::ACCOUNT_ID),
            new AccountName('Savings'),
            new AccountDescription('savings account'),
            new DateTimeImmutable(),
        )));
    }

    #[Test]
    public function queuesCreditEntryAsynchronously(): void
    {
        // when
        $this->post(self::ACCOUNT_ID, self::payload('credit', 150, '2026-10-01T12:00:00+02:00'));

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $messages = self::getSentMessages('rabbit');
        self::assertCount(1, $messages);
        $command = $messages[0];
        self::assertInstanceOf(AddAccountEntryCreditCommand::class, $command);
        self::assertSame(self::ACCOUNT_ID, $command->accountId->toRfc4122());
        self::assertSame(150, $command->credit->value);
        self::assertEquals(new DateTimeImmutable('2026-10-01T10:00:00+00:00'), $command->effectiveAt);
        self::assertSame('+02:00', $command->effectiveAt->format('P'));

        self::assertSame(['id' => $command->accountEntryId->toRfc4122()], $this->responseJson());
    }

    #[Test]
    public function queuesDebitEntryAsynchronously(): void
    {
        // when
        $this->post(self::ACCOUNT_ID, self::payload('debit', 70, '2026-10-01T12:00:00Z'));

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $messages = self::getSentMessages('rabbit');
        self::assertCount(1, $messages);
        $command = $messages[0];
        self::assertInstanceOf(AddAccountEntryDebitCommand::class, $command);
        self::assertSame(self::ACCOUNT_ID, $command->accountId->toRfc4122());
        self::assertSame(70, $command->debit->value);
        self::assertEquals(new DateTimeImmutable('2026-10-01T12:00:00+00:00'), $command->effectiveAt);

        self::assertSame(['id' => $command->accountEntryId->toRfc4122()], $this->responseJson());
    }

    #[Test]
    public function passesRequestCorrelationIdToQueuedCommand(): void
    {
        // given
        $correlationId = '0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091';

        // when
        $this->client->request(
            'POST',
            '/api/v1/account/'.self::ACCOUNT_ID.'/account-entry',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CORRELATION_ID' => $correlationId],
            content: json_encode(self::payload(), \JSON_THROW_ON_ERROR),
        );

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $transport = self::getContainer()->get('messenger.transport.rabbit');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = [...$transport->getSent()];
        self::assertCount(1, $sent);
        self::assertSame($correlationId, $sent[0]->last(CorrelationIdStamp::class)?->correlationId);
    }

    #[Test]
    public function returnsNotFoundForUnknownAccount(): void
    {
        // given
        $accountId = new AccountId()->toRfc4122();

        // when
        $this->post($accountId, self::payload());

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(
            [
                'type' => 'not-found',
                'status' => Response::HTTP_NOT_FOUND,
                'message' => \sprintf('Account with id "%s" not found', $accountId),
            ],
            $this->responseJson(),
        );
        self::assertSame([], self::getSentMessages('rabbit'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{propertyPath: string, message: string}>}>
     */
    public static function invalidPayload(): iterable
    {
        $valid = self::payload();

        yield 'missing side' => [
            array_diff_key($valid, ['side' => true]),
            [['propertyPath' => 'side', 'message' => 'This field is missing.']],
        ];
        yield 'unknown side' => [
            ['side' => 'both'] + $valid,
            [['propertyPath' => 'side', 'message' => 'The value you selected is not a valid choice.']],
        ];
        yield 'missing amount' => [
            array_diff_key($valid, ['amount' => true]),
            [['propertyPath' => 'amount', 'message' => 'This field is missing.']],
        ];
        yield 'amount as plain integer' => [
            ['amount' => 150] + $valid,
            [['propertyPath' => 'amount', 'message' => 'This value should be of type array.']],
        ];
        yield 'zero amount' => [
            ['amount' => ['value' => 0, 'currency' => 'PLN']] + $valid,
            [['propertyPath' => 'amount.value', 'message' => 'This value should be positive.']],
        ];
        yield 'negative amount' => [
            ['amount' => ['value' => -5, 'currency' => 'PLN']] + $valid,
            [['propertyPath' => 'amount.value', 'message' => 'This value should be positive.']],
        ];
        yield 'amount as string' => [
            ['amount' => ['value' => 'ten', 'currency' => 'PLN']] + $valid,
            [['propertyPath' => 'amount.value', 'message' => 'This value should be of type integer.']],
        ];
        yield 'unsupported currency' => [
            ['amount' => ['value' => 150, 'currency' => 'EUR']] + $valid,
            [['propertyPath' => 'amount.currency', 'message' => 'The value you selected is not a valid choice.']],
        ];
        yield 'missing effectiveAt' => [
            array_diff_key($valid, ['effectiveAt' => true]),
            [['propertyPath' => 'effectiveAt', 'message' => 'This field is missing.']],
        ];
        yield 'effectiveAt without timezone' => [
            ['effectiveAt' => '2026-10-01 12:00:00'] + $valid,
            [['propertyPath' => 'effectiveAt', 'message' => 'This value is not a valid datetime.']],
        ];
        yield 'effectiveAt ISO 8601 without timezone' => [
            ['effectiveAt' => '2026-10-01T12:00:00'] + $valid,
            [['propertyPath' => 'effectiveAt', 'message' => 'This value is not a valid datetime.']],
        ];
        yield 'invalid effectiveAt' => [
            ['effectiveAt' => 'yesterday-ish'] + $valid,
            [['propertyPath' => 'effectiveAt', 'message' => 'This value is not a valid datetime.']],
        ];
        yield 'extra field' => [
            ['note' => 'x'] + $valid,
            [['propertyPath' => 'note', 'message' => 'This field was not expected.']],
        ];
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $expectedViolations
     */
    #[Test]
    #[DataProvider('invalidPayload')]
    public function rejectsInvalidPayloadWithoutDispatching(array $payload, array $expectedViolations): void
    {
        // when
        $this->post(self::ACCOUNT_ID, $payload);

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(
            [
                'type' => 'request-validation',
                'status' => Response::HTTP_BAD_REQUEST,
                'message' => 'Request validation failed.',
                'violations' => $expectedViolations,
            ],
            $this->responseJson(),
        );
        self::assertSame([], self::getSentMessages('rabbit'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAccountId(): iterable
    {
        yield 'not a uuid' => ['nope', 'This is not a valid UUID.'];
        yield 'uuid v7' => ['01a131a9-79d5-7131-977d-15dd07f09b34', 'This is not a valid UUID.'];
    }

    #[Test]
    #[DataProvider('invalidAccountId')]
    public function returnsNotFoundForInvalidAccountId(string $accountId, string $expectedMessage): void
    {
        // when
        $this->post($accountId, self::payload());

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(
            [
                'type' => 'url-parameters-validation',
                'status' => Response::HTTP_NOT_FOUND,
                'message' => 'URL parameters validation failed.',
                'violations' => [['propertyPath' => 'accountId', 'message' => $expectedMessage]],
            ],
            $this->responseJson(),
        );
        self::assertSame([], self::getSentMessages('rabbit'));
    }

    #[Test]
    public function rejectsOtherMethods(): void
    {
        // when
        $this->client->request('GET', '/api/v1/account/'.self::ACCOUNT_ID.'/account-entry');

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * @return array{side: string, amount: array{value: int, currency: string}, effectiveAt: string}
     */
    private static function payload(
        string $side = 'credit',
        int $amount = 150,
        string $effectiveAt = '2026-10-01T12:00:00+02:00',
    ): array {
        return [
            'side' => $side,
            'amount' => ['value' => $amount, 'currency' => 'PLN'],
            'effectiveAt' => $effectiveAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $accountId, array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/v1/account/'.$accountId.'/account-entry',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJson(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }
}
