<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http\Logging;

use App\Shared\Infrastructure\Http\Logging\SensitiveDataMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SensitiveDataMaskerTest extends TestCase
{
    #[Test]
    public function masksSensitiveHeadersOnly(): void
    {
        // when
        $headers = new SensitiveDataMasker()->maskHeaders([
            'authorization' => ['Bearer abc'],
            'Set-Cookie' => ['a=1', 'b=2'],
            'x-api-key' => ['key'],
            'content-type' => ['application/json'],
        ]);

        // then
        self::assertSame(
            [
                'authorization' => ['***'],
                'Set-Cookie' => ['***', '***'],
                'x-api-key' => ['***'],
                'content-type' => ['application/json'],
            ],
            $headers,
        );
    }

    #[Test]
    public function masksSensitiveKeysRecursively(): void
    {
        // when
        $data = new SensitiveDataMasker()->maskData([
            'Password' => 'p',
            'access_token' => ['a' => 'b'],
            'client-secret' => 's',
            'iban' => 'PL61',
            'pin' => '1234',
            'spinner' => 'visible',
            'items' => [['cardNumber' => '4111', 'name' => 'visible']],
            0 => 'list value',
        ]);

        // then
        self::assertSame(
            [
                'Password' => '***',
                'access_token' => '***',
                'client-secret' => '***',
                'iban' => '***',
                'pin' => '***',
                'spinner' => 'visible',
                'items' => [['cardNumber' => '***', 'name' => 'visible']],
                0 => 'list value',
            ],
            $data,
        );
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function contents(): iterable
    {
        yield 'json' => ['{"token":"t","a":"ą/b"}', 'application/json', '{"token":"***","a":"ą/b"}'];
        yield 'json without content type' => ['[{"password":"p"}]', null, '[{"password":"***"}]'];
        yield 'invalid json' => ['{"password":', 'application/json', '{"password":'];
        yield 'json scalar' => ['"text"', 'application/json', '"text"'];
        yield 'form' => ['user=jan&password=p', 'application/x-www-form-urlencoded', 'user=jan&password=%2A%2A%2A'];
        yield 'plain text' => ['password=p', 'text/plain', 'password=p'];
        yield 'empty' => ['', 'application/json', ''];
    }

    #[Test]
    #[DataProvider('contents')]
    public function masksContentByType(string $content, ?string $contentType, string $expected): void
    {
        // when
        $masked = new SensitiveDataMasker()->maskContent($content, $contentType);

        // then
        self::assertSame($expected, $masked);
    }
}
