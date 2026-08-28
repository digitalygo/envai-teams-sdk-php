<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

use Digitalygo\Envai\Contracts\ResponseBodySink;

/**
 * Immutable description of a single HTTP request.
 *
 * Header names are normalized to lowercase and values are stored as a list,
 * so duplicate headers survive. Timeouts are expressed in seconds.
 */
final readonly class HttpRequest
{
    /**
     * @var array<string, list<string>>
     */
    public array $headers;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public string $method,
        public string $uri,
        array $headers = [],
        public string|MultipartBody|null $body = null,
        public ?ResponseBodySink $sink = null,
        public int $connectTimeoutSeconds = 10,
        public int $requestTimeoutSeconds = 60,
        public AuthenticationMode $auth = AuthenticationMode::Bearer,
        public RetrySafety $retrySafety = RetrySafety::Safe,
    ) {
        if ($method === '') {
            throw new \InvalidArgumentException('Method must not be empty.');
        }

        if ($uri === '') {
            throw new \InvalidArgumentException('URI must not be empty.');
        }

        if ($connectTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be a positive integer.');
        }

        if ($requestTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('requestTimeoutSeconds must be a positive integer.');
        }

        $this->headers = self::normalizeHeaders($headers);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function get(string $uri, array $headers = []): self
    {
        return new self('GET', $uri, $headers);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function post(string $uri, array $headers = [], string|MultipartBody|null $body = null): self
    {
        return new self('POST', $uri, $headers, $body);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function put(string $uri, array $headers = [], string|MultipartBody|null $body = null): self
    {
        return new self('PUT', $uri, $headers, $body);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function delete(string $uri, array $headers = [], string|MultipartBody|null $body = null): self
    {
        return new self('DELETE', $uri, $headers, $body);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[strtolower($name)][] = $value;

        return new self(
            $this->method,
            $this->uri,
            $headers,
            $this->body,
            $this->sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $this->auth,
            $this->retrySafety,
        );
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $merged = $this->headers;

        foreach (self::normalizeHeaders($headers) as $name => $values) {
            foreach ($values as $value) {
                $merged[$name][] = $value;
            }
        }

        return new self(
            $this->method,
            $this->uri,
            $merged,
            $this->body,
            $this->sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $this->auth,
            $this->retrySafety,
        );
    }

    public function withBody(string|MultipartBody|null $body): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $body,
            $this->sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $this->auth,
            $this->retrySafety,
        );
    }

    public function withSink(?ResponseBodySink $sink): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $this->auth,
            $this->retrySafety,
        );
    }

    public function withAuth(AuthenticationMode $auth): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $this->sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $auth,
            $this->retrySafety,
        );
    }

    public function withRetrySafety(RetrySafety $retrySafety): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $this->sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $this->auth,
            $retrySafety,
        );
    }

    public function withTimeouts(int $connectTimeoutSeconds, int $requestTimeoutSeconds): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $this->sink,
            $connectTimeoutSeconds,
            $requestTimeoutSeconds,
            $this->auth,
            $this->retrySafety,
        );
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
