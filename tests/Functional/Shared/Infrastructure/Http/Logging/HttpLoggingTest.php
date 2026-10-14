<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Http\Logging;

use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Account\Presentation\Http\PostAccountEntryController;
use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class HttpLoggingTest extends WebTestCase
{
    private const string ACCOUNT_ID = '9fc62b77-8dc6-4493-b245-6020d30573bd';
    private const string URI = '/api/v1/account/'.self::ACCOUNT_ID.'/account-entry';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function logsRequestAndResponseWithTheSameCorrelationId(): void
    {
        // given
        self::getContainer()->get(AccountRepository::class)->new(Account::create(new CreateAccountDto(
            new AccountId(self::ACCOUNT_ID),
            new AccountName('Savings'),
            new AccountDescription('savings account'),
            new DateTimeImmutable(),
        )));
        $payload = self::validPayload();

        // when
        $this->post($payload);

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $correlationId = $this->responseCorrelationId();
        self::assertTrue(Uuid::isValid($correlationId));

        $request = $this->singleRecord('HttpRequest');
        self::assertSame('http', $request->channel);
        self::assertSame(Level::Info, $request->level);
        self::assertSame($correlationId, $request->context['correlationId']);
        self::assertSame('generated', $request->context['correlationIdSource']);
        self::assertSame(['correlationId' => $correlationId, 'service' => 'symfony8'], $request->extra);
        self::assertSame('POST', $request->context['method']);
        self::assertSame(self::URI, $request->context['path']);
        self::assertSame('http://localhost'.self::URI, $request->context['uri']);
        self::assertSame('application/json', $request->context['contentType']);
        self::assertSame(json_encode($payload), $request->context['payload']);
        self::assertFalse($request->context['payloadTruncated']);

        $response = $this->singleRecord('HttpResponse');
        self::assertSame('http', $response->channel);
        self::assertSame(Level::Info, $response->level);
        self::assertSame($correlationId, $response->context['correlationId']);
        self::assertSame(['correlationId' => $correlationId, 'service' => 'symfony8'], $response->extra);
        self::assertSame(Response::HTTP_ACCEPTED, $response->context['status']);
        self::assertSame('api_v1_account_entry_add', $response->context['route']);
        self::assertSame(['accountId' => self::ACCOUNT_ID], $response->context['routeParameters']);
        self::assertSame(PostAccountEntryController::class.'::add', $response->context['controller']);
        self::assertSame($this->client->getResponse()->getContent(), $response->context['payload']);
        self::assertSame([$correlationId], $this->headers($response)['x-correlation-id'] ?? null);
        self::assertNull($response->context['exception']);
        self::assertIsFloat($response->context['durationMs']);
    }

    #[Test]
    public function usesValidCorrelationIdFromHeader(): void
    {
        // given
        $clientCorrelationId = '0192a6e8-1c2d-7f00-8a3b-4c5d6e7f8091';

        // when
        $this->post(self::validPayload(), ['HTTP_X_CORRELATION_ID' => $clientCorrelationId]);

        // then
        self::assertSame($clientCorrelationId, $this->responseCorrelationId());
        $request = $this->singleRecord('HttpRequest');
        self::assertSame($clientCorrelationId, $request->context['correlationId']);
        self::assertSame('header', $request->context['correlationIdSource']);
        self::assertSame($clientCorrelationId, $this->singleRecord('HttpResponse')->context['correlationId']);
    }

    #[Test]
    public function generatesCorrelationIdWhenHeaderIsNotUuid(): void
    {
        // when
        $this->post(self::validPayload(), ['HTTP_X_CORRELATION_ID' => 'not-a-uuid']);

        // then
        $correlationId = $this->responseCorrelationId();
        self::assertTrue(Uuid::isValid($correlationId));
        $request = $this->singleRecord('HttpRequest');
        self::assertSame($correlationId, $request->context['correlationId']);
        self::assertSame('generated', $request->context['correlationIdSource']);
        self::assertSame('not-a-uuid', $request->context['invalidClientCorrelationId']);
    }

    #[Test]
    public function logsRequestRejectedByRouter(): void
    {
        // when
        $this->client->request('GET', '/no-such-page');

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $correlationId = $this->responseCorrelationId();
        self::assertSame('/no-such-page', $this->singleRecord('HttpRequest')->context['path']);
        $response = $this->singleRecord('HttpResponse');
        self::assertSame($correlationId, $response->context['correlationId']);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->context['status']);
        self::assertNull($response->context['route']);
        self::assertSame(NotFoundHttpException::class, $response->context['exception']);
    }

    #[Test]
    public function addsCorrelationIdToOtherLogsOfTheRequest(): void
    {
        // when
        $this->post(['side' => 'x']);

        // then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $correlationId = $this->responseCorrelationId();
        $validationLog = $this->singleRecord('Request validation failed.');
        self::assertSame('app', $validationLog->channel);
        self::assertSame(['correlationId' => $correlationId, 'service' => 'symfony8'], $validationLog->extra);
        self::assertSame(
            RequestValidationException::class,
            $this->singleRecord('HttpResponse')->context['exception'],
        );
    }

    #[Test]
    public function masksSensitiveHeadersAndPayloadFields(): void
    {
        // when
        $this->post(
            ['password' => 'p4ss', 'nested' => ['access_token' => 't0ken', 'note' => 'visible']],
            ['HTTP_AUTHORIZATION' => 'Bearer secret', 'HTTP_COOKIE' => 'session=abc'],
        );

        // then
        $request = $this->singleRecord('HttpRequest');
        $headers = $this->headers($request);
        self::assertSame(['***'], $headers['authorization'] ?? null);
        self::assertSame(['***'], $headers['cookie'] ?? null);
        self::assertSame(
            '{"password":"***","nested":{"access_token":"***","note":"visible"}}',
            $request->context['payload'],
        );
    }

    #[Test]
    public function excludesProfilerRequests(): void
    {
        // when
        $this->client->request('GET', '/_wdt/abc');

        // then
        self::assertSame([], $this->records('HttpRequest'));
        self::assertSame([], $this->records('HttpResponse'));
        self::assertFalse($this->client->getResponse()->headers->has('X-Correlation-Id'));
    }

    /**
     * @return array{side: string, amount: array{value: int, currency: string}, effectiveAt: string}
     */
    private static function validPayload(): array
    {
        return [
            'side' => 'credit',
            'amount' => ['value' => 150, 'currency' => 'PLN'],
            'effectiveAt' => '2026-10-01T12:00:00+02:00',
        ];
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server
     */
    private function post(array $payload, array $server = []): void
    {
        $this->client->request(
            'POST',
            self::URI,
            server: ['CONTENT_TYPE' => 'application/json'] + $server,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function responseCorrelationId(): string
    {
        $correlationId = $this->client->getResponse()->headers->get('X-Correlation-Id');
        self::assertIsString($correlationId);

        return $correlationId;
    }

    /**
     * @return list<LogRecord>
     */
    private function records(string $message): array
    {
        // defined only in when@test, PHPStan reads the dev container
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $handler = self::getContainer()->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);

        return array_values(array_filter(
            $handler->getRecords(),
            static fn (LogRecord $record): bool => $message === $record->message,
        ));
    }

    private function singleRecord(string $message): LogRecord
    {
        $records = $this->records($message);
        self::assertCount(1, $records, \sprintf('Expected exactly one "%s" log record.', $message));

        return $records[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(LogRecord $record): array
    {
        $headers = $record->context['headers'] ?? null;
        self::assertIsArray($headers);

        /** @var array<string, mixed> $headers */
        return $headers;
    }
}
