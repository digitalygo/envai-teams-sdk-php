<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Exceptions\EnvaiException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\DownloadResult;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Psr\Log\LoggerInterface;

/**
 * Executes HTTP requests with default header injection, retries and typed
 * decoding.
 *
 * Default headers (Authorization, Accept, User-Agent) are injected only when
 * absent. Retries apply exclusively to RetrySafety::Safe requests, only for
 * retryable statuses or connect-phase transport failures, and honor
 * Retry-After through the RetryPolicy. Logging happens at debug level and
 * never includes headers, bodies or credentials. URIs in log messages
 * are redacted through UriRedactor (userinfo, query and fragment are
 * stripped), so signed artifact URLs and webhook callback tokens never
 * reach the log stream.
 */
final readonly class RequestExecutor
{
    private JsonCodec $codec;

    /**
     * Best-effort markers suggesting the failure happened while reading or
     * writing the response, after the connection was established.
     *
     * @var list<string>
     */
    private const RESPONSE_PHASE_MARKERS = ['response', 'chunk', 'premature', 'stream', 'partial', 'transfer', 'body'];

    /**
     * Best-effort markers suggesting the failure happened during connection
     * setup, before any response byte could have been processed.
     *
     * @var list<string>
     */
    private const CONNECT_PHASE_MARKERS = [
        'connection refused',
        'failed to connect',
        'unable to connect',
        'could not connect',
        'connect() timed out',
        'connection timed out',
        'dns',
        'resolve',
        'name or service',
        'no route to host',
        'unreachable',
        'handshake',
        'ssl',
        'tls',
    ];

    public function __construct(
        private HttpTransport $transport,
        private UriResolver $uris,
        private ?RetryPolicy $retryPolicy = null,
        private ?LoggerInterface $logger = null,
        private ?SensitiveString $apiKey = null,
    ) {
        $this->codec = new JsonCodec;
    }

    /**
     * Sends a request, applying default headers and the retry policy, and
     * returns the final HttpResponse (which may still carry an error status;
     * the caller decides how to map it).
     */
    public function send(HttpRequest $request): HttpResponse
    {
        return $this->execute($this->applyDefaultHeaders($request), 1);
    }

    /**
     * Builds a request against the UriResolver, sends it, maps error statuses
     * through the ErrorMapper and decodes the success body according to the
     * envelope mode.
     *
     * @param  array<string, mixed>  $query
     * @param  class-string<ArrayHydratable>  $class  DTO (or collection item class).
     */
    public function apiRequest(
        string $method,
        string $path,
        array $query,
        mixed $body,
        string $class,
        EnvelopeMode $mode = EnvelopeMode::Envelope,
    ): object {
        $encodedBody = $this->codec->encodeRequestBody($body);
        $headers = $encodedBody !== null ? ['Content-Type' => 'application/json'] : [];

        $request = new HttpRequest($method, $this->uris->api($path, $query), $headers, $encodedBody);

        $response = $this->send($request);

        if ($response->status >= 400) {
            throw ErrorMapper::fromStatusAndBody($response->status, $response->body, $response->headers);
        }

        return match ($mode) {
            EnvelopeMode::Envelope => $this->codec->decodeEnvelopeInto((string) $response->body, $class),
            EnvelopeMode::Collection => $this->codec->decodeCollectionInto((string) $response->body, $class),
            EnvelopeMode::TopLevel => $this->codec->decodeTopLevelInto((string) $response->body, $class),
        };
    }

    /**
     * Streams a download into the request sink and returns download metadata.
     *
     * Streaming requests are never retried: bytes may already have been
     * written to the sink, so replaying the request would duplicate data.
     *
     * @throws TransportException on network failures.
     * @throws EnvaiException on error statuses.
     */
    public function sendStreaming(HttpRequest $request): DownloadResult
    {
        $request = $this->applyDefaultHeaders($request);

        $this->log(sprintf('Streaming %s %s.', $request->method, UriRedactor::redact($request->uri)));

        try {
            $response = $this->transport->send($request);
        } catch (TransportException $e) {
            $this->log(sprintf('Streaming transport failure for %s %s: %s.', $request->method, UriRedactor::redact($request->uri), $e->getMessage()));

            throw $e;
        }

        if ($response->status >= 400) {
            throw ErrorMapper::fromStatusAndBody($response->status, null, $response->headers);
        }

        return new DownloadResult(
            mediaType: $this->mediaType($response->headers),
            filename: $this->filename($response->headers),
            contentLength: $this->contentLength($response->headers),
            headers: $response->headers,
        );
    }

    /**
     * Best-effort classification of whether a transport failure happened
     * during the connection phase (retryable) or while exchanging the
     * response (not retryable, since bytes may have been processed).
     *
     * Response-phase markers win over connection-phase markers; unknown
     * messages are conservatively classified as not retryable.
     */
    public static function classifyTransportFailure(TransportException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (self::RESPONSE_PHASE_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return false;
            }
        }

        foreach (self::CONNECT_PHASE_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Injects the default headers unless the caller already provided them.
     */
    private function applyDefaultHeaders(HttpRequest $request): HttpRequest
    {
        if ($request->auth === AuthenticationMode::Bearer && $this->apiKey !== null && ! isset($request->headers['authorization'])) {
            $request = $request->withHeader('authorization', 'Bearer '.$this->apiKey->reveal());
        }

        if (! isset($request->headers['accept'])) {
            $request = $request->withHeader('accept', 'application/json');
        }

        if (! isset($request->headers['user-agent'])) {
            $request = $request->withHeader('user-agent', sprintf('envai-php/%s (php/%s)', SdkVersion::VERSION, PHP_VERSION));
        }

        return $request;
    }

    private function execute(HttpRequest $request, int $attempt): HttpResponse
    {
        $this->log(sprintf('Sending %s %s (attempt %d).', $request->method, UriRedactor::redact($request->uri), $attempt));

        try {
            $response = $this->transport->send($request);
        } catch (TransportException $e) {
            $this->log(sprintf('Transport failure for %s %s: %s.', $request->method, UriRedactor::redact($request->uri), $e->getMessage()));

            if ($this->canRetryTransport($request, $e, $attempt)) {
                $this->log(sprintf('Retrying %s %s after transport failure (attempt %d).', $request->method, UriRedactor::redact($request->uri), $attempt));
                $this->delay($request, $attempt + 1, null);

                return $this->execute($request, $attempt + 1);
            }

            throw $e;
        }

        if ($this->canRetryStatus($request, $response, $attempt)) {
            $retryAfter = ErrorMapper::retryAfterSeconds($response->headers);
            $this->log(sprintf('Retrying %s %s after HTTP %d (attempt %d).', $request->method, UriRedactor::redact($request->uri), $response->status, $attempt));
            $this->delay($request, $attempt + 1, $retryAfter);

            return $this->execute($request, $attempt + 1);
        }

        $this->log(sprintf('Received HTTP %d for %s %s (attempt %d).', $response->status, $request->method, UriRedactor::redact($request->uri), $attempt));

        return $response;
    }

    private function canRetryStatus(HttpRequest $request, HttpResponse $response, int $attempt): bool
    {
        if ($request->retrySafety !== RetrySafety::Safe) {
            return false;
        }

        if ($this->retryPolicy === null || $attempt >= $this->retryPolicy->maxAttempts) {
            return false;
        }

        return $this->retryPolicy->isRetryableStatus($response->status);
    }

    private function canRetryTransport(HttpRequest $request, TransportException $exception, int $attempt): bool
    {
        if ($request->retrySafety !== RetrySafety::Safe) {
            return false;
        }

        if ($this->retryPolicy === null || $attempt >= $this->retryPolicy->maxAttempts) {
            return false;
        }

        return self::classifyTransportFailure($exception);
    }

    private function delay(HttpRequest $request, int $nextAttempt, ?float $retryAfterSeconds): void
    {
        $policy = $this->retryPolicy;

        if ($policy === null) {
            return;
        }

        $delayMs = $policy->delayMs($nextAttempt, $retryAfterSeconds);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function mediaType(array $headers): string
    {
        $value = $headers['content-type'][0] ?? '';

        if ($value === '') {
            return 'application/octet-stream';
        }

        return trim(explode(';', $value)[0]);
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function filename(array $headers): ?string
    {
        $value = trim($headers['content-disposition'][0] ?? '');

        if ($value === '') {
            return null;
        }

        // RFC 5987 encoded filename (filename*=UTF-8''...).
        if (preg_match('/filename\*\s*=\s*(?:UTF-8|utf-8)\'\'([^;]+)/', $value, $match) === 1) {
            return rawurldecode($match[1]);
        }

        if (preg_match('/filename\s*=\s*"([^"]*)"/', $value, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/filename\s*=\s*([^;]+)/', $value, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function contentLength(array $headers): ?int
    {
        $value = $headers['content-length'][0] ?? '';

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function log(string $message): void
    {
        $this->logger?->debug($message);
    }
}
