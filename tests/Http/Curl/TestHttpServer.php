<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Curl;

/**
 * Minimal raw TCP HTTP server for deterministic offline functional tests.
 *
 * Binds an ephemeral port on 127.0.0.1 and serves exactly one connection.
 * The handler receives the raw request bytes (request line, headers, body)
 * and writes the handcrafted response with respond(); its return value is
 * stored as a JSON summary so the parent process can assert on what the
 * server observed.
 */
final class TestHttpServer
{
    /** @var resource|null */
    private $server;

    /** @var resource|null */
    private $connection = null;

    private ?string $error = null;

    public function __construct()
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new \RuntimeException(sprintf('Unable to bind the test server: %s (%d)', $errstr, $errno));
        }

        $this->server = $server;
    }

    /**
     * Builds the base URL of the server, for example http://127.0.0.1:41234.
     */
    public function url(string $path = ''): string
    {
        $address = stream_socket_get_name($this->server, false);

        return $path === '' ? 'http://'.$address : 'http://'.$address.$path;
    }

    /**
     * Returns the failure message recorded while serving, if any.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Accepts one connection, reads the request and runs the handler.
     *
     * @param  callable(string $request, self $server): array<string, mixed>  $handler
     */
    public function serve(callable $handler, string $summaryFile): void
    {
        try {
            $connection = @stream_socket_accept($this->server, 10);

            if ($connection === false) {
                $this->error = 'accept timed out after 10 seconds';

                return;
            }

            $this->connection = $connection;
            $imported = @socket_import_stream($connection);

            if ($imported !== false) {
                @socket_set_option($imported, SOL_TCP, TCP_NODELAY, 1);
            }

            stream_set_timeout($connection, 10);

            $request = $this->readRequest();

            /** @var array<string, mixed> $summary */
            $summary = $handler($request, $this);

            @file_put_contents($summaryFile, (string) json_encode($summary, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            @file_put_contents(
                $summaryFile,
                (string) json_encode(['handler_error' => $e->getMessage(), 'exception_class' => $e::class], JSON_THROW_ON_ERROR),
            );
        } finally {
            $this->closeAll();
        }
    }

    /**
     * Writes a raw chunk to the accepted connection.
     */
    public function respond(string $data): void
    {
        if ($this->connection === null) {
            return;
        }

        @fwrite($this->connection, $data);
    }

    /**
     * Polls the connection until the client closes it (EOF) or the deadline.
     *
     * Used by slow scenarios: the client is expected to time out or cancel,
     * and the server must not block the test forever afterwards.
     */
    public function waitForClientClose(float $maxSeconds = 10.0): void
    {
        $deadline = microtime(true) + $maxSeconds;

        while (microtime(true) < $deadline) {
            if ($this->connection === null) {
                return;
            }

            $read = [$this->connection];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 100_000);

            if ($ready === false) {
                return;
            }

            if ($ready > 0) {
                $byte = fread($this->connection, 1);

                if ($byte === false || $byte === '') {
                    return; // EOF: the client closed the connection
                }
            }
        }
    }

    private function readRequest(): string
    {
        $request = '';

        while (! str_contains($request, "\r\n\r\n")) {
            $chunk = fread($this->connection, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $request .= $chunk;

            if (strlen($request) > 8 * 1024 * 1024) {
                break;
            }
        }

        if (preg_match('/\r\ncontent-length:\s*(\d+)/i', $request, $matches) === 1) {
            $length = (int) $matches[1];
            $headerEnd = strpos($request, "\r\n\r\n") + 4;

            while (strlen($request) - $headerEnd < $length) {
                $chunk = fread($this->connection, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $request .= $chunk;
            }
        } elseif (preg_match('/\r\ntransfer-encoding:\s*chunked/i', $request) === 1) {
            $request .= $this->readChunkedBody();
        }

        return $request;
    }

    private function readChunkedBody(): string
    {
        $body = '';

        while (true) {
            $line = fgets($this->connection);

            if ($line === false) {
                break;
            }

            $size = hexdec(trim($line));

            if ($size === 0) {
                fread($this->connection, 2); // trailing CRLF after the final chunk

                break;
            }

            $remaining = $size;

            while ($remaining > 0) {
                $part = fread($this->connection, $remaining);

                if ($part === false || $part === '') {
                    break 2;
                }

                $body .= $part;
                $remaining -= strlen($part);
            }

            fread($this->connection, 2); // CRLF after each chunk
        }

        return $body;
    }

    private function closeAll(): void
    {
        if ($this->connection !== null) {
            fclose($this->connection);
            $this->connection = null;
        }

        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }
    }
}
