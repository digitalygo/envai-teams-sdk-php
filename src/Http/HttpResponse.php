<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

/**
 * Immutable HTTP response.
 *
 * Header names are normalized to lowercase; values preserve duplicates as a
 * list. The body is the full response body for non-streaming responses.
 */
final readonly class HttpResponse
{
    /**
     * @var array<string, list<string>>
     */
    public array $headers;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public int $status,
        array $headers = [],
        public ?string $body = null,
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('status must be between 100 and 599.');
        }

        $this->headers = self::normalizeHeaders($headers);
    }

    /**
     * Returns the first value of the given header (case-insensitive) or null.
     */
    public function header(string $name): ?string
    {
        return $this->headerValues($name)[0] ?? null;
    }

    /**
     * Returns every value of the given header (case-insensitive).
     *
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isInformational(): bool
    {
        return $this->status >= 100 && $this->status < 200;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500 && $this->status < 600;
    }

    public function isOk(): bool
    {
        return $this->status === 200;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $key = strtolower((string) $name);

            foreach (is_array($values) ? $values : [$values] as $value) {
                $normalized[$key][] = $value;
            }
        }

        return $normalized;
    }
}
