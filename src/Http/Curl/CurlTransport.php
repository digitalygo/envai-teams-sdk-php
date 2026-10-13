<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Curl;

use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\StreamRequest;

/**
 * cURL backed transport for one-shot requests and SSE streams.
 *
 * Authentication is not this layer's concern: headers are honored verbatim.
 * Transport level failures (DNS, connect, TLS, timeouts) surface as
 * TransportException whose message includes the cURL error code but never the
 * request headers. Responses are buffered in memory up to a hard cap unless
 * the request carries a ResponseBodySink, in which case body chunks stream
 * straight into the sink.
 */
final class CurlTransport implements EventStreamTransport, HttpTransport
{
    private const MAX_BUFFERED_RESPONSE_BYTES = 64 * 1024 * 1024;

    public function send(HttpRequest $request): HttpResponse
    {
        $easy = curl_init($request->uri);

        if ($easy === false) {
            throw new TransportException('Unable to initialize cURL for "'.CurlOptionsBuilder::sanitizeUri($request->uri).'".');
        }

        $collector = new CurlHeaderCollector;
        $sink = $request->sink;
        $buffer = '';

        $options = CurlOptionsBuilder::forRequest($request);
        $options[CURLOPT_HEADERFUNCTION] = function (\CurlHandle $handle, string $line) use ($collector): int {
            $collector->feed($line);

            return strlen($line);
        };

        if ($sink !== null) {
            $options[CURLOPT_WRITEFUNCTION] = function (\CurlHandle $handle, string $chunk) use ($sink): int {
                $sink->write($chunk);

                return strlen($chunk);
            };
        } else {
            $options[CURLOPT_WRITEFUNCTION] = function (\CurlHandle $handle, string $chunk) use (&$buffer): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_BUFFERED_RESPONSE_BYTES) {
                    throw new TransportException(sprintf(
                        'Response body exceeds the %d MiB in-memory limit; provide a ResponseBodySink for large downloads.',
                        self::MAX_BUFFERED_RESPONSE_BYTES / (1024 * 1024),
                    ));
                }

                $buffer .= $chunk;

                return strlen($chunk);
            };
        }

        curl_setopt_array($easy, $options);

        curl_exec($easy);

        $errno = curl_errno($easy);
        $status = curl_getinfo($easy, CURLINFO_RESPONSE_CODE);

        if ($errno !== CURLE_OK) {
            throw $this->sendError($errno, $request);
        }

        if ($status === 0) {
            throw new TransportException('The server did not return an HTTP status code.');
        }

        if ($sink !== null) {
            $sink->complete();
        }

        return new HttpResponse($status, $collector->headers(), $sink === null ? $buffer : null);
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        $easy = curl_init($request->uri);

        if ($easy === false) {
            throw new TransportException('Unable to initialize cURL for "'.CurlOptionsBuilder::sanitizeUri($request->uri).'".');
        }

        $multi = curl_multi_init();

        curl_multi_add_handle($multi, $easy);

        $connection = new CurlEventStreamConnection($easy, $multi, $request, CurlOptionsBuilder::forStream($request));

        try {
            $connection->awaitHeaders();
        } catch (\Throwable $e) {
            $connection->close();

            throw $e;
        }

        $status = $connection->statusCode();

        if ($status >= 400) {
            $connection->close();

            throw new TransportException(sprintf(
                'The SSE endpoint %s returned HTTP %d.',
                CurlOptionsBuilder::sanitizeUri($request->uri),
                $status,
            ), ['http_status' => $status]);
        }

        if (! $connection->hasEventStreamContentType()) {
            $contentType = $connection->headers()['content-type'][0] ?? '(missing)';
            $connection->close();

            throw new TransportException(sprintf(
                'The SSE endpoint %s returned Content-Type "%s"; expected "text/event-stream".',
                CurlOptionsBuilder::sanitizeUri($request->uri),
                $contentType,
            ));
        }

        return $connection;
    }

    private function sendError(int $errno, HttpRequest $request): TransportException
    {
        $uri = CurlOptionsBuilder::sanitizeUri($request->uri);
        $detail = curl_strerror($errno) ?: 'unknown cURL error';

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $message = sprintf(
                'cURL transport error %d (%s) while requesting %s (connect timeout %d seconds, request timeout %d seconds).',
                $errno,
                $detail,
                $uri,
                $request->connectTimeoutSeconds,
                $request->requestTimeoutSeconds,
            );
        } else {
            $message = sprintf('cURL transport error %d (%s) while requesting %s.', $errno, $detail, $uri);
        }

        return new TransportException($message, ['curl_errno' => $errno]);
    }
}
