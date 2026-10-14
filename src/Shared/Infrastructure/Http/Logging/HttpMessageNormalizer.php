<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Logging;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns Request / Response into log context: masked headers and payload, payload cut to the configured size
 * (app.context is a "flattened" field in Elasticsearch, a value over 32 KB rejects the whole document).
 */
readonly class HttpMessageNormalizer
{
    public function __construct(
        private SensitiveDataMasker $masker,
        #[Autowire(env: 'int:HTTP_LOG_MAX_PAYLOAD_BYTES')]
        private int $maxPayloadBytes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeRequest(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content && 0 < $request->request->count()) {
            // multipart/form-data: PHP does not expose the raw body
            $content = (string) json_encode(
                $request->request->all(),
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
        }
        $contentType = $request->headers->get('Content-Type');

        return [
            'method' => $request->getMethod(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'path' => $request->getPathInfo(),
            'uri' => $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo(),
            'query' => $this->masker->maskData($request->query->all()),
            'protocolVersion' => $request->getProtocolVersion(),
            'clientIp' => $request->getClientIp(),
            'clientIps' => $request->getClientIps(),
            'headers' => $this->masker->maskHeaders($request->headers->all()),
            'contentType' => $contentType,
            'contentLength' => \strlen($content),
            ...$this->payload($this->masker->maskContent($content, $contentType)),
            'files' => $this->files($request->files->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeResponse(Request $request, Response $response): array
    {
        $streamed = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
        $content = $streamed ? '' : (string) $response->getContent();
        $contentType = $response->headers->get('Content-Type');
        $routeParameters = $request->attributes->get('_route_params');

        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $request->attributes->get('_route'),
            'routeParameters' => \is_array($routeParameters) ? $this->masker->maskData($routeParameters) : null,
            'controller' => $this->controller($request->attributes->get('_controller')),
            'status' => $response->getStatusCode(),
            'statusText' => Response::$statusTexts[$response->getStatusCode()] ?? null,
            'protocolVersion' => $response->getProtocolVersion(),
            'headers' => $this->masker->maskHeaders($response->headers->all()),
            'contentType' => $contentType,
            'contentLength' => $streamed ? $response->headers->get('Content-Length') : \strlen($content),
            ...($streamed
                ? ['payload' => null, 'payloadTruncated' => false]
                : $this->payload($this->masker->maskContent($content, $contentType))),
            'responseType' => $response::class,
        ];
    }

    /**
     * @return array{payload: string, payloadTruncated: bool}
     */
    private function payload(string $content): array
    {
        if ($this->maxPayloadBytes >= \strlen($content)) {
            return ['payload' => $content, 'payloadTruncated' => false];
        }

        return ['payload' => mb_strcut($content, 0, $this->maxPayloadBytes), 'payloadTruncated' => true];
    }

    /**
     * Metadata only, never the file content.
     *
     * @param array<mixed> $files
     *
     * @return list<array{field: string, name: string, size: int|false|null, mimeType: string, error: int}>
     */
    private function files(array $files, string $prefix = ''): array
    {
        $result = [];
        foreach ($files as $field => $file) {
            $name = '' === $prefix ? (string) $field : $prefix.'['.$field.']';
            if ($file instanceof UploadedFile) {
                $result[] = [
                    'field' => $name,
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->isValid() ? $file->getSize() : null,
                    'mimeType' => $file->getClientMimeType(),
                    'error' => $file->getError(),
                ];
            } elseif (\is_array($file)) {
                array_push($result, ...$this->files($file, $name));
            }
        }

        return $result;
    }

    private function controller(mixed $controller): ?string
    {
        return match (true) {
            null === $controller => null,
            \is_string($controller) => $controller,
            \is_array($controller) && 2 === \count($controller) && \is_string($controller[1]) => \sprintf(
                '%s::%s',
                \is_string($controller[0]) ? $controller[0] : get_debug_type($controller[0]),
                $controller[1],
            ),
            default => get_debug_type($controller),
        };
    }
}
