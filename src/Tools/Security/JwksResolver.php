<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Security;

use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\HttpRequest;

/**
 * Fetches and caches the control plane JWKS document and resolves signing
 * keys by `kid`.
 *
 * The transport is either an HttpTransport (the JWKS URL is requested with a
 * plain GET) or a simple callable `function (string $jwksUrl): string` that
 * returns the raw JWKS JSON body, which keeps the resolver usable in
 * environments that wire the fetch differently. The document is cached with
 * an internal TTL (default 300 seconds); a clock callable can be injected to
 * make expiry deterministic in tests.
 *
 * Resolution rules (verified before handler execution):
 *
 * - a cache hit serves the key without a network round trip;
 * - a cache miss (no cache, or expired) fetches exactly once and serves the
 *   key from the fresh document;
 * - an unknown kid on a fresh cache triggers exactly ONE forced refresh per
 *   get() call, after which an unknown kid throws;
 * - a failed refresh never discards a still-valid cached document, so a
 *   transient transport failure does not wipe a usable cache.
 *
 * The cache is process-local: multi-worker deployments refresh per worker
 * and the ToolRequestVerifier tolerates rotation by refreshing once per
 * unknown kid. Fetch failures surface as TransportException or ApiException;
 * malformed documents and unknown keys after refresh surface as
 * ProtocolViolationException.
 */
final class JwksResolver
{
    private const DEFAULT_TTL_SECONDS = 300;

    private ?JwksDocument $cache = null;

    private ?\DateTimeImmutable $fetchedAt = null;

    /**
     * @var \Closure(string): string
     */
    private readonly HttpTransport|\Closure $transport;

    /**
     * @param  HttpTransport|(callable(string): string)  $transport  HTTP transport or callable returning the raw JWKS JSON.
     * @param  \Closure(): \DateTimeImmutable|null  $clock  Clock used for TTL decisions, injectable for tests.
     */
    public function __construct(
        HttpTransport|callable $transport,
        private readonly string $jwksUrl,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly ?\Closure $clock = null,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('ttlSeconds must be a positive integer.');
        }

        if ($jwksUrl === '') {
            throw new \InvalidArgumentException('jwksUrl must not be empty.');
        }

        $this->transport = $transport instanceof HttpTransport ? $transport : \Closure::fromCallable($transport);
    }

    /**
     * Fetches the JWKS document from the control plane, updates the cache
     * and returns it. The previous cache is preserved when the fetch fails.
     */
    public function fetch(): JwksDocument
    {
        $document = $this->fetchDocument();
        $this->cache = $document;
        $this->fetchedAt = $this->now();

        return $document;
    }

    /**
     * Resolves the signing key for the given kid, performing at most one
     * network fetch per call (either the initial load or a forced refresh
     * after an unknown kid).
     *
     * @throws ProtocolViolationException when the kid is still unknown after
     *                                    the forced refresh.
     */
    public function get(string $kid): JwkEntry
    {
        $fresh = $this->freshCache();

        if ($fresh !== null) {
            $entry = $fresh->get($kid);

            if ($entry !== null) {
                return $entry;
            }
        }

        $refreshed = $this->fetch();
        $entry = $refreshed->get($kid);

        if ($entry === null) {
            throw new ProtocolViolationException(sprintf(
                'Unknown signing key "%s" after JWKS refresh.',
                $kid,
            ));
        }

        return $entry;
    }

    private function freshCache(): ?JwksDocument
    {
        if ($this->cache === null || $this->fetchedAt === null) {
            return null;
        }

        $elapsedSeconds = $this->now()->getTimestamp() - $this->fetchedAt->getTimestamp();

        return $elapsedSeconds < $this->ttlSeconds ? $this->cache : null;
    }

    private function fetchDocument(): JwksDocument
    {
        if ($this->transport instanceof HttpTransport) {
            $response = $this->transport->send(HttpRequest::get($this->jwksUrl));

            if (! $response->isSuccess()) {
                throw ApiException::fromResponse($response);
            }

            $body = $response->body ?? '';

            if ($body === '') {
                throw new ProtocolViolationException('Control plane JWKS endpoint returned an empty body.');
            }

            return JwksDocument::fromJson($body);
        }

        $body = ($this->transport)($this->jwksUrl);

        return JwksDocument::fromJson($body);
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock === null) {
            return new \DateTimeImmutable('now');
        }

        return ($this->clock)();
    }
}
