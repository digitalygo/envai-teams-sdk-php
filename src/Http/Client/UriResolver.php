<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

/**
 * Resolves API paths and the JWKS endpoint against a configured base URL.
 *
 * The canonical API prefix is /api/v1. The base URL may already end with it
 * (matched case-insensitively) or not: the resolver always produces exactly
 * one canonical prefix and never duplicates the prefix when the path carries
 * it. Query strings are percent-encoded with RFC 3986.
 */
final readonly class UriResolver
{
    private const API_PREFIX = '/api/v1';

    /** Base URL without the trailing slash, as provided by the caller. */
    private string $baseUrl;

    /** Base URL plus exactly one canonical /api/v1 prefix. */
    private string $apiBase;

    public function __construct(
        string $baseUrl,
        private ?string $jwksUrl = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');

        self::assertHttpUrl($this->baseUrl, 'baseUrl');

        if ($this->jwksUrl !== null) {
            self::assertHttpUrl($this->jwksUrl, 'jwksUrl');
        }

        $this->apiBase = str_ends_with(strtolower($this->baseUrl), self::API_PREFIX)
            ? $this->baseUrl
            : $this->baseUrl.self::API_PREFIX;
    }

    /**
     * Builds the absolute URL for an API path, appending the canonical
     * /api/v1 prefix exactly once and encoding the query with RFC 3986.
     *
     * The path may start with a leading slash or not, and may already carry
     * the /api/v1 prefix; the prefix is never duplicated.
     *
     * @param  array<string, mixed>  $query
     */
    public function api(string $path, array $query = []): string
    {
        $url = $this->apiBase.$this->canonicalizePath($path);

        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Returns the JWKS URL: the explicitly configured one when provided,
     * otherwise the well-known JWKS endpoint derived from the base URL origin
     * (scheme://host[:port]), never from the /api/v1 path prefix.
     */
    public function jwksUrl(): string
    {
        if ($this->jwksUrl !== null) {
            return $this->jwksUrl;
        }

        return $this->origin().'/.well-known/jwks.json';
    }

    public function wikiWebhookJwksUrl(): string
    {
        return $this->origin().'/.well-known/wiki-webhook-jwks.json';
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException('baseUrl was already validated in the constructor.');
        }

        $origin = (string) $parts['scheme'].'://'.(string) $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.(string) $parts['port'];
        }

        return $origin;
    }

    /**
     * Normalizes the path: strips a leading /api/v1 prefix so it is never
     * duplicated, and ensures a single leading slash.
     */
    private function canonicalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new \InvalidArgumentException('path must not be empty.');
        }

        $withoutLeading = ltrim($path, '/');
        $lower = strtolower($withoutLeading);

        if ($lower === 'api/v1' || str_starts_with($lower, 'api/v1/')) {
            $withoutLeading = substr($withoutLeading, strlen(self::API_PREFIX));
        }

        if ($withoutLeading === '') {
            return '';
        }

        return '/'.$withoutLeading;
    }

    private static function assertHttpUrl(string $url, string $name): void
    {
        if ($url === '') {
            throw new \InvalidArgumentException($name.' must be a non-empty http(s) URL.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new \InvalidArgumentException($name.' must be a valid http(s) URL.');
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException($name.' must use the http or https scheme.');
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException($name.' must be a valid http(s) URL.');
        }
    }
}
