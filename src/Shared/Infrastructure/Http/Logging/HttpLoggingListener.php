<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Logging;

use App\Shared\Infrastructure\Logging\CorrelationIdStorage;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Logs every main request as "HttpRequest" before anything else runs, and its response as "HttpResponse" after it has
 * been sent, both with the same correlationId (also returned in the X-Correlation-Id header). A "HttpRequest" without
 * a matching "HttpResponse" means the process died; when PHP still runs shutdown functions, "HttpRequestAborted"
 * is logged as well.
 */
#[WithMonologChannel('http')]
class HttpLoggingListener implements ResetInterface
{
    public const string CORRELATION_ID_HEADER = 'X-Correlation-Id';

    private const string ATTRIBUTE_CORRELATION_ID = '_correlation_id';
    private const string ATTRIBUTE_EXCEPTION = '_http_logging_exception';

    /** Released on shutdown so "HttpRequestAborted" can still be logged after running out of memory. */
    private const int MEMORY_RESERVE_BYTES = 32768;
    private const int MAX_CLIENT_CORRELATION_ID_LENGTH = 255;

    /** @var array{correlationId: string, method: string, path: string, startedAt: float}|null */
    private ?array $pendingRequest = null;

    /** @phpstan-ignore property.onlyWritten (held only to be freed in onShutdown()) */
    private ?string $memoryReserve = null;

    /**
     * @param list<string> $excludedPathPrefixes
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CorrelationIdStorage $correlationIdStorage,
        private readonly HttpMessageNormalizer $normalizer,
        private readonly array $excludedPathPrefixes = ['/_profiler', '/_wdt'],
    ) {
    }

    /**
     * Before RouterListener (32) and ValidateRequestListener (256): both throw in kernel.request, so a later
     * listener would never log 404/405 or requests with conflicting proxy headers.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->isExcluded($request)) {
            return;
        }

        $clientCorrelationId = $request->headers->get(self::CORRELATION_ID_HEADER);
        $useClientCorrelationId = null !== $clientCorrelationId && Uuid::isValid($clientCorrelationId);
        $correlationId = $useClientCorrelationId ? $clientCorrelationId : new UuidV7()->toRfc4122();

        $this->correlationIdStorage->set($correlationId);
        $request->attributes->set(self::ATTRIBUTE_CORRELATION_ID, $correlationId);

        $context = [
            'correlationId' => $correlationId,
            'correlationIdSource' => $useClientCorrelationId ? 'header' : 'generated',
        ];
        if (null !== $clientCorrelationId && !$useClientCorrelationId) {
            $context['invalidClientCorrelationId'] = mb_substr(
                $clientCorrelationId,
                0,
                self::MAX_CLIENT_CORRELATION_ID_LENGTH,
            );
        }

        $this->logger->info('HttpRequest', $context + $this->normalize(
            fn (): array => $this->normalizer->normalizeRequest($request),
        ));

        $this->pendingRequest = [
            'correlationId' => $correlationId,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'startedAt' => $this->startedAt($request),
        ];
        $this->memoryReserve = str_repeat('x', self::MEMORY_RESERVE_BYTES);
        register_shutdown_function($this->onShutdown(...), $correlationId);
    }

    /**
     * Before ExceptionListener, which stops propagation.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 1024)]
    public function onException(ExceptionEvent $event): void
    {
        $event->getRequest()->attributes->set(self::ATTRIBUTE_EXCEPTION, $event->getThrowable()::class);
    }

    /**
     * After SessionListener (-1000), so the headers are final.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -2048)]
    public function onResponse(ResponseEvent $event): void
    {
        $correlationId = $event->getRequest()->attributes->get(self::ATTRIBUTE_CORRELATION_ID);
        if (!$event->isMainRequest() || !\is_string($correlationId)) {
            return;
        }

        $event->getResponse()->headers->set(self::CORRELATION_ID_HEADER, $correlationId);
    }

    /**
     * After the response has been sent (PHP-FPM: after fastcgi_finish_request()), so logging does not delay it.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $correlationId = $request->attributes->get(self::ATTRIBUTE_CORRELATION_ID);
        if (!\is_string($correlationId)) {
            return;
        }

        $exception = $request->attributes->get(self::ATTRIBUTE_EXCEPTION);

        $this->logger->info('HttpResponse', ['correlationId' => $correlationId] + $this->normalize(
            fn (): array => $this->normalizer->normalizeResponse($request, $event->getResponse()),
        ) + [
            'exception' => \is_string($exception) ? $exception : null,
            'durationMs' => $this->durationMs($this->startedAt($request)),
            'memoryPeakBytes' => memory_get_peak_usage(true),
        ]);

        if (null !== $this->pendingRequest && $correlationId === $this->pendingRequest['correlationId']) {
            $this->pendingRequest = null;
            $this->memoryReserve = null;
        }
    }

    /**
     * Registered per request; does nothing once "HttpResponse" has been logged. A fatal error handled by Symfony's
     * ErrorHandler (registered earlier, so it runs first) still goes through kernel.terminate.
     */
    public function onShutdown(string $correlationId): void
    {
        if (null === $this->pendingRequest || $correlationId !== $this->pendingRequest['correlationId']) {
            return;
        }

        $this->memoryReserve = null;
        $pendingRequest = $this->pendingRequest;
        $this->pendingRequest = null;

        try {
            $this->logger->critical('HttpRequestAborted', [
                'correlationId' => $correlationId,
                'method' => $pendingRequest['method'],
                'path' => $pendingRequest['path'],
                'error' => error_get_last(),
                'durationMs' => $this->durationMs($pendingRequest['startedAt']),
                'memoryPeakBytes' => memory_get_peak_usage(true),
            ]);
        } catch (Throwable) {
            // nothing more can be done during shutdown
        }
    }

    public function reset(): void
    {
        $this->pendingRequest = null;
        $this->memoryReserve = null;
    }

    /**
     * Logging must never break the request: a failure (e.g. untrusted host header) is logged instead of the data.
     *
     * @param callable(): array<string, mixed> $normalize
     *
     * @return array<string, mixed>
     */
    private function normalize(callable $normalize): array
    {
        try {
            return $normalize();
        } catch (Throwable $exception) {
            return ['normalizationError' => $exception::class.': '.$exception->getMessage()];
        }
    }

    private function isExcluded(Request $request): bool
    {
        foreach ($this->excludedPathPrefixes as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function startedAt(Request $request): float
    {
        $requestTime = $request->server->get('REQUEST_TIME_FLOAT');

        return is_numeric($requestTime) ? (float) $requestTime : microtime(true);
    }

    private function durationMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 1);
    }
}
