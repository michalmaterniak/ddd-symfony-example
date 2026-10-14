<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http\Logging;

use App\Shared\Infrastructure\Http\Logging\HttpLoggingListener;
use App\Shared\Infrastructure\Http\Logging\HttpMessageNormalizer;
use App\Shared\Infrastructure\Http\Logging\SensitiveDataMasker;
use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpLoggingListenerTest extends TestCase
{
    private TestHandler $handler;

    private CorrelationIdStorage $storage;

    private HttpLoggingListener $listener;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->storage = new CorrelationIdStorage();
        $this->listener = new HttpLoggingListener(
            new Logger('http', [$this->handler]),
            $this->storage,
            new HttpMessageNormalizer(new SensitiveDataMasker(), 16384),
        );
    }

    #[Test]
    public function logsAbortedRequestOnShutdownWhenResponseWasNotLogged(): void
    {
        // given
        $request = Request::create('/api/x', 'POST');
        $this->listener->onRequest($this->requestEvent($request));
        $correlationId = $this->storage->get();
        self::assertIsString($correlationId);

        // when
        $this->listener->onShutdown($correlationId);

        // then
        $records = $this->handler->getRecords();
        self::assertCount(2, $records);
        self::assertSame('HttpRequestAborted', $records[1]->message);
        self::assertSame(Level::Critical, $records[1]->level);
        self::assertSame($correlationId, $records[1]->context['correlationId']);
        self::assertSame('POST', $records[1]->context['method']);
        self::assertSame('/api/x', $records[1]->context['path']);
        self::assertArrayHasKey('error', $records[1]->context);
    }

    #[Test]
    public function logsNothingOnShutdownAfterResponseWasLogged(): void
    {
        // given
        $request = Request::create('/api/x');
        $this->listener->onRequest($this->requestEvent($request));
        $correlationId = $this->storage->get();
        self::assertIsString($correlationId);
        $this->listener->onTerminate(new TerminateEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            new Response(),
        ));

        // when
        $this->listener->onShutdown($correlationId);

        // then
        self::assertSame(
            ['HttpRequest', 'HttpResponse'],
            array_map(static fn ($record): string => $record->message, $this->handler->getRecords()),
        );
    }

    #[Test]
    public function ignoresSubRequests(): void
    {
        // when
        $this->listener->onRequest(new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::SUB_REQUEST,
        ));

        // then
        self::assertSame([], $this->handler->getRecords());
        self::assertNull($this->storage->get());
    }

    #[Test]
    public function logsNormalizationErrorInsteadOfFailingTheRequest(): void
    {
        // given
        Request::setTrustedHosts(['^example\.com$']);
        $request = Request::create('http://evil.com/');

        // when
        try {
            $this->listener->onRequest($this->requestEvent($request));
        } finally {
            Request::setTrustedHosts([]);
        }

        // then
        $records = $this->handler->getRecords();
        self::assertSame('HttpRequest', $records[0]->message);
        self::assertIsString($records[0]->context['normalizationError']);
        self::assertStringContainsString('Untrusted Host', $records[0]->context['normalizationError']);
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
