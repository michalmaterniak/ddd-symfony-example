<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Logging;

/**
 * Replaces secrets in logged HTTP headers and payloads with {@see self::MASK}.
 */
class SensitiveDataMasker
{
    public const string MASK = '***';

    private const array SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
    ];

    /** Keys are compared lowercased, without "-" and "_" ("access_token" -> "accesstoken"). */
    private const array SENSITIVE_KEY_PARTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'authorization',
        'cookie',
    ];
    private const array SENSITIVE_KEYS = ['iban', 'cardnumber', 'cvv', 'cvc', 'pin'];

    /**
     * @param array<string, list<string|null>> $headers
     *
     * @return array<string, list<string|null>>
     */
    public function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (\in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
                $headers[$name] = array_map(static fn (): string => self::MASK, $values);
            }
        }

        return $headers;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public function maskData(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif (\is_array($value)) {
                $data[$key] = $this->maskData($value);
            }
        }

        return $data;
    }

    /**
     * Masks JSON and form-urlencoded bodies; other content (or invalid JSON) is returned as is.
     */
    public function maskContent(string $content, ?string $contentType): string
    {
        if ('' === $content) {
            return $content;
        }

        $contentType = strtolower($contentType ?? '');

        $trimmed = ltrim($content);
        if (str_contains($contentType, 'json') || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $data = json_decode($content, true);

            return \is_array($data)
                ? (string) json_encode($this->maskData($data), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                : $content;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($content, $data);

            return http_build_query($this->maskData($data));
        }

        return $content;
    }

    private function isSensitiveKey(int|string $key): bool
    {
        if (\is_int($key)) {
            return false;
        }

        $normalized = str_replace(['-', '_'], '', strtolower($key));
        if (\in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }
}
