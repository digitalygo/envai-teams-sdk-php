<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

use Digitalygo\Envai\Contracts\CancellationToken;

/**
 * Describes an HTTP request that expects a streaming (SSE) response.
 *
 * responseHeaderTimeoutSeconds bounds the wait for the response headers,
 * streamTimeoutSeconds bounds the total stream duration (null means no
 * deadline) and the optional token enables cooperative cancellation.
 */
final readonly class StreamRequest
{
    /**
     * @var array<string, list<string>>
     */
    public array $headers;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public string $uri,
        array $headers = [],
        public int $connectTimeoutSeconds = 10,
        public int $responseHeaderTimeoutSeconds = 30,
        public ?int $streamTimeoutSeconds = null,
        public ?CancellationToken $cancellationToken = null,
    ) {
        if ($uri === '') {
            throw new \InvalidArgumentException('URI must not be empty.');
        }

        if ($connectTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be a positive integer.');
        }

        if ($responseHeaderTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('responseHeaderTimeoutSeconds must be a positive integer.');
        }

        if ($streamTimeoutSeconds !== null && $streamTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('streamTimeoutSeconds must be a positive integer when provided.');
        }

        $this->headers = self::normalizeHeaders($headers);
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
