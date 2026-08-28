<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Curl;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\StreamRequest;

/**
 * Streaming SSE connection backed by an easy handle on a private multi handle.
 *
 * read() pumps the multi handle in bounded 100 ms select intervals so blocking
 * reads observe both new bytes and cooperative cancellation. The transfer is
 * aborted with CURLE_ABORTED_BY_CALLBACK when the progress hook notices a
 * cancelled token; clean EOF (server close) yields null from read().
 *
 * The connection is not thread-safe and must be consumed from a single
 * execution context. The consumer owns the lifecycle and must call close();
 * the destructor is a fallback only.
 */
final class CurlEventStreamConnection implements EventStreamConnection
{
    private ?\CurlHandle $easy;

    private ?\CurlMultiHandle $multi;

    private CurlHeaderCollector $collector;

    private StreamRequest $request;

    private string $buffer = '';

    private int $statusCode = 0;

    private bool $cancelled = false;

    private bool $closed = false;

    private bool $eof = false;

    /**
     * @param  array<int|string, mixed>  $options  Static options from CurlOptionsBuilder.
     */
    public function __construct(\CurlHandle $easy, \CurlMultiHandle $multi, StreamRequest $request, array $options)
    {
        $this->easy = $easy;
        $this->multi = $multi;
        $this->request = $request;
        $this->collector = new CurlHeaderCollector;

        $options[CURLOPT_HEADERFUNCTION] = function (\CurlHandle $handle, string $line): int {
            $this->collector->feed($line);

            return strlen($line);
        };
        $options[CURLOPT_WRITEFUNCTION] = function (\CurlHandle $handle, string $chunk): int {
            $this->buffer .= $chunk;

            return strlen($chunk);
        };
        $options[CURLOPT_XFERINFOFUNCTION] = function (\CurlHandle $handle, int $downloadTotal, int $downloadNow, int $uploadTotal, int $uploadNow): int {
            if ($this->token()?->isCancelled() === true) {
                return 1; // abort the transfer, detected as cooperative cancellation
            }

            return 0;
        };

        curl_setopt_array($easy, $options);
    }

    public function read(int $maxBytes = 8192): ?string
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be a positive integer.');
        }

        if ($this->closed || $this->cancelled) {
            return null;
        }

        while (true) {
            if ($this->buffer !== '') {
                $chunk = substr($this->buffer, 0, $maxBytes);
                $this->buffer = substr($this->buffer, $maxBytes);

                return $chunk;
            }

            if ($this->eof) {
                return null;
            }

            $this->pumpOnce();

            if ($this->cancelled) {
                return null;
            }

            if ($this->eof && $this->buffer === '') {
                return null;
            }

            if ($this->token()?->isCancelled() === true) {
                $this->cancel();

                return null;
            }

            $this->select();
        }
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->collector->headers();
    }

    public function cancel(): void
    {
        if ($this->cancelled || $this->closed) {
            return;
        }

        $this->cancelled = true;
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->easy !== null && $this->multi !== null) {
            @curl_multi_remove_handle($this->multi, $this->easy);
        }

        if ($this->easy !== null) {
            $this->easy = null; // freed by reference counting
        }

        if ($this->multi !== null) {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Pumps the multi handle until the response headers are complete.
     *
     * Called by CurlTransport::open() before the connection is returned to the
     * caller. The header deadline from StreamRequest is enforced here as a
     * wall clock bound because CURLOPT_TIMEOUT_MS would kill the whole stream.
     *
     * @throws OperationCancelledException when cancellation is requested first.
     * @throws TransportException on transfer errors or header deadline expiry.
     */
    public function awaitHeaders(): void
    {
        if ($this->token()?->isCancelled() === true) {
            $this->cancelled = true;

            throw new OperationCancelledException('The stream was cancelled while waiting for response headers.');
        }

        $deadline = microtime(true) + $this->request->responseHeaderTimeoutSeconds;

        while (true) {
            $this->pumpOnce();

            if ($this->collector->isComplete()) {
                break;
            }

            if ($this->cancelled) {
                throw new OperationCancelledException('The stream was cancelled while waiting for response headers.');
            }

            if ($this->eof) {
                throw new TransportException(sprintf(
                    'The connection to %s closed before response headers were received.',
                    CurlOptionsBuilder::sanitizeUri($this->request->uri),
                ));
            }

            if ($this->token()?->isCancelled() === true) {
                $this->cancelled = true;

                throw new OperationCancelledException('The stream was cancelled while waiting for response headers.');
            }

            if (microtime(true) >= $deadline) {
                throw new TransportException(sprintf(
                    'Timed out after %d seconds waiting for response headers from %s.',
                    $this->request->responseHeaderTimeoutSeconds,
                    CurlOptionsBuilder::sanitizeUri($this->request->uri),
                ));
            }

            $this->select();
        }

        if ($this->easy !== null) {
            $this->statusCode = curl_getinfo($this->easy, CURLINFO_RESPONSE_CODE);
        }
    }

    /**
     * Returns whether the response claims to be a text/event-stream body.
     */
    public function hasEventStreamContentType(): bool
    {
        $values = $this->collector->headers()['content-type'] ?? [];

        return $values !== [] && self::isEventStreamContentType($values[0]);
    }

    /**
     * Returns whether a Content-Type value is text/event-stream, ignoring any
     * charset parameter.
     */
    public static function isEventStreamContentType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'text/event-stream';
    }

    private function pumpOnce(): void
    {
        if ($this->multi === null || $this->easy === null) {
            $this->eof = true;

            return;
        }

        $running = 0;
        $code = curl_multi_exec($this->multi, $running);

        if ($code !== CURLM_OK) {
            throw new TransportException('The cURL multi handle failed with code '.$code.'.');
        }

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $result = $info['result'];
            $errno = is_int($result) ? $result : 0;

            if ($errno === CURLE_OK) {
                $this->eof = true;

                continue;
            }

            if ($errno === CURLE_ABORTED_BY_CALLBACK) {
                // Only the progress hook aborts transfers, always as
                // cooperative cancellation.
                $this->cancelled = true;
                $this->eof = true;

                continue;
            }

            throw $this->transferError($errno);
        }
    }

    private function select(): void
    {
        if ($this->multi === null) {
            return;
        }

        $result = curl_multi_select($this->multi, 0.1);

        if ($result === -1) {
            usleep(100_000);
        }
    }

    private function token(): ?CancellationToken
    {
        return $this->request->cancellationToken;
    }

    private function transferError(int $errno): TransportException
    {
        $uri = CurlOptionsBuilder::sanitizeUri($this->request->uri);
        $detail = curl_strerror($errno) ?: 'unknown cURL error';

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $message = sprintf(
                'cURL stream error %d (%s) while reading from %s (stream deadline %s).',
                $errno,
                $detail,
                $uri,
                $this->request->streamTimeoutSeconds !== null ? $this->request->streamTimeoutSeconds.' seconds' : 'none',
            );
        } else {
            $message = sprintf('cURL stream error %d (%s) while reading from %s.', $errno, $detail, $uri);
        }

        return new TransportException($message, ['curl_errno' => $errno]);
    }
}
