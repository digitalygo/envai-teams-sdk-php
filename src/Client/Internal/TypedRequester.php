<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client\Internal;

use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Exceptions\EnvaiException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\ErrorMapper;
use Digitalygo\Envai\Http\Client\JsonCodec;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\DownloadResult;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Common\ArrayHydratable;

/**
 * Typed request helper shared by every service.
 *
 * The RequestExecutor::apiRequest() shorthand cannot express
 * AuthenticationMode::None (public endpoints), RetrySafety::NeverRetry
 * (mutations and streams) or sinks, so services build requests explicitly
 * through this helper instead. It mirrors the executor's decoding contract
 * (envelope, collection, top-level) and maps error statuses through the
 * ErrorMapper, applying the configured timeouts to every request.
 */
final readonly class TypedRequester
{
    private JsonCodec $codec;

    public function __construct(
        private RequestExecutor $executor,
        private UriResolver $uris,
        private int $connectTimeoutSeconds = 10,
        private int $requestTimeoutSeconds = 60,
    ) {
        $this->codec = new JsonCodec;
    }

    /**
     * Substitutes the `{placeholder}` segments of an OpenAPI path template
     * with their concrete values (RFC 3986 encoded).
     *
     * @param  array<string, string>  $params
     */
    public function resolve(string $template, array $params): string
    {
        $path = $template;

        foreach ($params as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }

        return $path;
    }

    /**
     * Builds the absolute URL for a resolved API path, appending the
     * canonical /api/v1 prefix exactly once and encoding the query with RFC
     * 3986.
     *
     * @param  array<string, mixed>  $query
     */
    public function url(string $path, array $query = []): string
    {
        return $this->uris->api($path, $query);
    }

    /**
     * Returns the JWKS URL: the explicitly configured one when provided,
     * otherwise the well-known endpoint derived from the base URL origin.
     */
    public function urlForJwks(): string
    {
        return $this->uris->jwksUrl();
    }

    public function urlForWikiWebhookJwks(): string
    {
        return $this->uris->wikiWebhookJwksUrl();
    }

    /**
     * Sends a JSON request and decodes the success body according to the
     * envelope mode.
     *
     * @param  array<string, mixed>  $query
     * @param  class-string<ArrayHydratable>  $class
     */
    public function request(
        string $method,
        string $path,
        array $query,
        mixed $body,
        string $class,
        EnvelopeMode $mode,
        AuthenticationMode $auth = AuthenticationMode::Bearer,
        RetrySafety $retry = RetrySafety::Safe,
        ?string $idempotencyKey = null,
    ): object {
        $response = $this->execute($this->build($method, $this->uris->api($path, $query), $body, $auth, $retry, idempotencyKey: $idempotencyKey));

        return match ($mode) {
            EnvelopeMode::Envelope => $this->codec->decodeEnvelopeInto((string) $response->body, $class),
            EnvelopeMode::Collection => $this->codec->decodeCollectionInto((string) $response->body, $class),
            EnvelopeMode::TopLevel => $this->codec->decodeTopLevelInto((string) $response->body, $class),
        };
    }

    /**
     * Sends a JSON request against an absolute URL (not routed through the
     * canonical /api/v1 prefix, for example the JWKS well-known endpoint)
     * and decodes the success body according to the envelope mode.
     *
     * @param  array<string, mixed>  $query
     * @param  class-string<ArrayHydratable>  $class
     */
    public function absolute(
        string $method,
        string $url,
        array $query,
        mixed $body,
        string $class,
        EnvelopeMode $mode,
        AuthenticationMode $auth = AuthenticationMode::None,
        RetrySafety $retry = RetrySafety::Safe,
    ): object {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $response = $this->execute($this->build($method, $url, $body, $auth, $retry));

        return match ($mode) {
            EnvelopeMode::Envelope => $this->codec->decodeEnvelopeInto((string) $response->body, $class),
            EnvelopeMode::Collection => $this->codec->decodeCollectionInto((string) $response->body, $class),
            EnvelopeMode::TopLevel => $this->codec->decodeTopLevelInto((string) $response->body, $class),
        };
    }

    /**
     * Sends a request expecting a body-less success (HTTP 204). Error
     * statuses are still mapped through the ErrorMapper.
     *
     * @param  array<string, mixed>  $query
     */
    public function noContent(
        string $method,
        string $path,
        array $query,
        mixed $body,
        AuthenticationMode $auth = AuthenticationMode::Bearer,
        RetrySafety $retry = RetrySafety::NeverRetry,
    ): void {
        $this->execute($this->build($method, $this->uris->api($path, $query), $body, $auth, $retry));
    }

    /**
     * Sends a JSON request and returns the decoded top-level JSON object as
     * an array, without hydrating a DTO. Used for payloads whose shape is
     * not a single-resource envelope, for example the Laravel paginator
     * wire shape of the completions listing.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function payload(
        string $method,
        string $path,
        array $query,
        mixed $body,
        AuthenticationMode $auth = AuthenticationMode::Bearer,
        RetrySafety $retry = RetrySafety::Safe,
    ): array {
        $response = $this->execute($this->build($method, $this->uris->api($path, $query), $body, $auth, $retry));

        try {
            $decoded = json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolViolationException('Malformed JSON in the response body.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new ProtocolViolationException('Expected a JSON object response body.');
        }

        return self::stringKeyed($decoded);
    }

    /**
     * Sends a multipart request and decodes the success body according to
     * the envelope mode.
     *
     * @param  array<string, mixed>  $query
     * @param  class-string<ArrayHydratable>  $class
     */
    public function multipart(
        string $method,
        string $path,
        array $query,
        MultipartBody $body,
        string $class,
        EnvelopeMode $mode,
        AuthenticationMode $auth = AuthenticationMode::Bearer,
        RetrySafety $retry = RetrySafety::NeverRetry,
        ?string $idempotencyKey = null,
    ): object {
        $request = new HttpRequest(
            $method,
            $this->uris->api($path, $query),
            array_merge(['content-type' => $body->contentType()], self::idempotencyHeaders($idempotencyKey)),
            $body,
            auth: $auth,
            retrySafety: $retry,
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            requestTimeoutSeconds: $this->requestTimeoutSeconds,
        );

        $response = $this->execute($request);

        return match ($mode) {
            EnvelopeMode::Envelope => $this->codec->decodeEnvelopeInto((string) $response->body, $class),
            EnvelopeMode::Collection => $this->codec->decodeCollectionInto((string) $response->body, $class),
            EnvelopeMode::TopLevel => $this->codec->decodeTopLevelInto((string) $response->body, $class),
        };
    }

    /**
     * Streams a download (optionally into a sink) through the executor.
     * Streaming requests are never retried: bytes may already have been
     * written to the sink, so replaying the request would duplicate data.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws EnvaiException on error statuses.
     */
    public function stream(
        string $method,
        string $path,
        array $query,
        mixed $body,
        ?ResponseBodySink $sink,
        AuthenticationMode $auth = AuthenticationMode::Bearer,
        RetrySafety $retry = RetrySafety::NeverRetry,
    ): DownloadResult {
        return $this->executor->sendStreaming($this->build($method, $this->uris->api($path, $query), $body, $auth, $retry, $sink));
    }

    private function build(
        string $method,
        string $url,
        mixed $body,
        AuthenticationMode $auth,
        RetrySafety $retry,
        ?ResponseBodySink $sink = null,
        ?string $idempotencyKey = null,
    ): HttpRequest {
        $encoded = $this->codec->encodeRequestBody($body);
        $headers = array_merge($encoded !== null ? ['content-type' => 'application/json'] : [], self::idempotencyHeaders($idempotencyKey));

        return new HttpRequest(
            $method,
            $url,
            $headers,
            $encoded,
            $sink,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $auth,
            $retry,
        );
    }

    /** @return array<string, string> */
    private static function idempotencyHeaders(?string $key): array
    {
        if ($key === null) {
            return [];
        }
        if (preg_match('/^[\x21-\x7e]{1,255}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Idempotency key must contain 1 to 255 printable non-space ASCII bytes.');
        }

        return ['idempotency-key' => $key];
    }

    private function execute(HttpRequest $request): HttpResponse
    {
        $response = $this->executor->send($request);

        if ($response->status >= 400) {
            throw ErrorMapper::fromStatusAndBody($response->status, $response->body, $response->headers);
        }

        return $response;
    }

    /**
     * Keeps only string keys, matching the shape json_decode produces for a
     * JSON object.
     *
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
