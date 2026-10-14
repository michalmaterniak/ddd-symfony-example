<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http\Logging;

use App\Shared\Infrastructure\Http\Logging\HttpMessageNormalizer;
use App\Shared\Infrastructure\Http\Logging\SensitiveDataMasker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HttpMessageNormalizerTest extends TestCase
{
    #[Test]
    public function normalizesRequest(): void
    {
        // given
        $request = Request::create(
            uri: 'https://example.com:8443/path?a=1&token=t',
            method: 'PUT',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer x',
                'REMOTE_ADDR' => '10.0.0.1',
            ],
            content: '{"a":1}',
        );

        // when
        $context = $this->normalizer()->normalizeRequest($request);

        // then
        self::assertSame('PUT', $context['method']);
        self::assertSame('https', $context['scheme']);
        self::assertSame('example.com', $context['host']);
        self::assertSame(8443, $context['port']);
        self::assertSame('/path', $context['path']);
        self::assertSame('https://example.com:8443/path', $context['uri']);
        self::assertSame(['a' => '1', 'token' => '***'], $context['query']);
        self::assertSame('10.0.0.1', $context['clientIp']);
        self::assertSame(['10.0.0.1'], $context['clientIps']);
        self::assertIsArray($context['headers']);
        self::assertSame(['***'], $context['headers']['authorization']);
        self::assertSame('application/json', $context['contentType']);
        self::assertSame(7, $context['contentLength']);
        self::assertSame('{"a":1}', $context['payload']);
        self::assertFalse($context['payloadTruncated']);
        self::assertSame([], $context['files']);
    }

    #[Test]
    public function truncatesPayloadToMaxBytesWithoutBreakingMultibyteCharacters(): void
    {
        // given
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: 'abcdefghą');

        // when
        $context = $this->normalizer(maxPayloadBytes: 9)->normalizeRequest($request);

        // then
        self::assertSame('abcdefgh', $context['payload']);
        self::assertTrue($context['payloadTruncated']);
        self::assertSame(10, $context['contentLength']);
    }

    #[Test]
    public function logsFormFieldsAndFileMetadataOfMultipartRequest(): void
    {
        // given
        $path = tempnam(sys_get_temp_dir(), 'upload');
        self::assertIsString($path);
        file_put_contents($path, 'file content');
        $request = Request::create(
            uri: '/upload',
            method: 'POST',
            parameters: ['name' => 'doc', 'password' => 'p'],
            files: ['attachments' => [new UploadedFile($path, 'a.pdf', 'application/pdf', null, true)]],
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'],
        );

        // when
        $context = $this->normalizer()->normalizeRequest($request);

        // then
        self::assertSame('{"name":"doc","password":"***"}', $context['payload']);
        self::assertSame(
            [[
                'field' => 'attachments[0]',
                'name' => 'a.pdf',
                'size' => 12,
                'mimeType' => 'application/pdf',
                'error' => 0,
            ]],
            $context['files'],
        );
        unlink($path);
    }

    #[Test]
    public function normalizesResponse(): void
    {
        // given
        $request = Request::create('/api/x/1');
        $request->attributes->add([
            '_route' => 'api_x',
            '_route_params' => ['id' => '1'],
            '_controller' => [new stdClass(), 'show'],
        ]);
        $response = new JsonResponse(['token' => 't', 'id' => 1], Response::HTTP_CREATED);
        $response->headers->setCookie(new Cookie('session', 'abc'));

        // when
        $context = $this->normalizer()->normalizeResponse($request, $response);

        // then
        self::assertSame('GET', $context['method']);
        self::assertSame('/api/x/1', $context['path']);
        self::assertSame('api_x', $context['route']);
        self::assertSame(['id' => '1'], $context['routeParameters']);
        self::assertSame('stdClass::show', $context['controller']);
        self::assertSame(201, $context['status']);
        self::assertSame('Created', $context['statusText']);
        self::assertIsArray($context['headers']);
        self::assertSame(['***'], $context['headers']['set-cookie']);
        self::assertSame('application/json', $context['contentType']);
        self::assertSame('{"token":"***","id":1}', $context['payload']);
        self::assertSame(JsonResponse::class, $context['responseType']);
    }

    #[Test]
    public function doesNotReadStreamedResponseContent(): void
    {
        // given
        $response = new StreamedResponse(static function (): void {
            echo 'streamed';
        });

        // when
        $context = $this->normalizer()->normalizeResponse(Request::create('/'), $response);

        // then
        self::assertNull($context['payload']);
        self::assertFalse($context['payloadTruncated']);
        self::assertNull($context['controller']);
        self::assertNull($context['route']);
        self::assertSame(StreamedResponse::class, $context['responseType']);
    }

    private function normalizer(int $maxPayloadBytes = 16384): HttpMessageNormalizer
    {
        return new HttpMessageNormalizer(new SensitiveDataMasker(), $maxPayloadBytes);
    }
}
