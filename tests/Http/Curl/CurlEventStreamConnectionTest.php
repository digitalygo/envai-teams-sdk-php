<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Curl;

use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\CancellationSource;
use Digitalygo\Envai\Http\Curl\CurlTransport;
use Digitalygo\Envai\Http\StreamRequest;

final class CurlEventStreamConnectionTest extends CurlFunctionalTestCase
{
    public function test_open_validates_status_and_content_type_ignoring_charset(): void
    {
        $connection = null;

        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond(
                    "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream; charset=utf-8\r\n\r\nretry: 3000\n\n",
                );

                return [];
            },
            function (string $baseUrl) use (&$connection): void {
                $connection = (new CurlTransport)->open(new StreamRequest(
                    $baseUrl.'/events',
                    ['Accept' => 'text/event-stream'],
                    streamTimeoutSeconds: 10,
                ));

                $this->assertSame(200, $connection->statusCode());
                $this->assertSame(['text/event-stream; charset=utf-8'], $connection->headers()['content-type']);
                $this->assertSame("retry: 3000\n\n", $connection->read());
                $this->assertFalse($connection->isClosed());
                $this->assertFalse($connection->isCancelled());
            },
        );

        // The server has closed the connection: the stream ends cleanly.
        $this->assertNull($connection->read());
        $this->assertTrue($connection->isClosed() === false);

        $connection->close();
        $this->assertTrue($connection->isClosed());
    }

    public function test_read_blocks_until_data_arrives_and_returns_null_at_eof(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\n");
                usleep(300_000);
                $server->respond("data: one\n\n");
                usleep(300_000);
                $server->respond("data: two\n\n");
                usleep(200_000);

                return [];
            },
            function (string $baseUrl): void {
                $connection = (new CurlTransport)->open(new StreamRequest(
                    $baseUrl.'/events',
                    ['Accept' => 'text/event-stream'],
                    streamTimeoutSeconds: 10,
                ));

                try {
                    $started = microtime(true);
                    $first = $connection->read();
                    $this->assertSame("data: one\n\n", $first);
                    $this->assertGreaterThan(0.2, microtime(true) - $started);

                    $this->assertSame("data: two\n\n", $connection->read());
                    $this->assertNull($connection->read()); // EOF after server close
                } finally {
                    $connection->close();
                }
            },
        );
    }

    public function test_cancel_stops_the_stream_and_read_returns_null(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\ndata: first\n\n");
                $server->waitForClientClose(10);

                return [];
            },
            function (string $baseUrl): void {
                $connection = (new CurlTransport)->open(new StreamRequest(
                    $baseUrl.'/events',
                    ['Accept' => 'text/event-stream'],
                    streamTimeoutSeconds: 10,
                ));

                $this->assertSame("data: first\n\n", $connection->read());

                $connection->cancel();
                $this->assertTrue($connection->isCancelled());
                $this->assertTrue($connection->isClosed());
                $this->assertNull($connection->read());

                // Both are idempotent after cancellation.
                $connection->close();
                $connection->cancel();
                $this->assertNull($connection->read());
            },
        );
    }

    public function test_read_returns_null_when_token_cancelled_while_blocked(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\n");
                usleep(500_000);
                $server->respond("data: late\n\n");
                $server->waitForClientClose(10);

                return [];
            },
            function (string $baseUrl): void {
                $source = new CancellationSource;
                $connection = (new CurlTransport)->open(new StreamRequest(
                    $baseUrl.'/events',
                    ['Accept' => 'text/event-stream'],
                    streamTimeoutSeconds: 10,
                    cancellationToken: $source,
                ));

                // Cancel while the next read would otherwise block on the
                // server's sleep: read() must stop and return null.
                $source->cancel();
                $this->assertNull($connection->read());
                $this->assertTrue($connection->isCancelled());

                $connection->close();
            },
        );
    }

    public function test_open_throws_operation_cancelled_when_token_already_cancelled(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $address = stream_socket_get_name($probe, false);
        fclose($probe); // free the port: the token check must fire before connecting

        $source = new CancellationSource;
        $source->cancel();

        try {
            (new CurlTransport)->open(new StreamRequest(
                'http://'.$address.'/events',
                ['Accept' => 'text/event-stream'],
                responseHeaderTimeoutSeconds: 30,
                cancellationToken: $source,
            ));
            $this->fail('Expected OperationCancelledException.');
        } catch (OperationCancelledException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }

    public function test_open_throws_transport_exception_for_non_2xx_status(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 404 Not Found\r\nContent-Type: text/event-stream\r\nContent-Length: 0\r\n\r\n");

                return [];
            },
            function (string $baseUrl): void {
                try {
                    (new CurlTransport)->open(new StreamRequest(
                        $baseUrl.'/missing',
                        ['Accept' => 'text/event-stream'],
                        streamTimeoutSeconds: 10,
                    ));
                    $this->fail('Expected TransportException for HTTP 404.');
                } catch (TransportException $e) {
                    $this->assertStringContainsString('404', $e->getMessage());
                }
            },
        );
    }

    public function test_open_throws_when_content_type_is_not_event_stream(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 5\r\n\r\n<h1></h1>");

                return [];
            },
            function (string $baseUrl): void {
                try {
                    (new CurlTransport)->open(new StreamRequest(
                        $baseUrl.'/html',
                        ['Accept' => 'text/event-stream'],
                        streamTimeoutSeconds: 10,
                    ));
                    $this->fail('Expected TransportException for a non-event-stream content type.');
                } catch (TransportException $e) {
                    $this->assertStringContainsString('text/event-stream', $e->getMessage());
                    $this->assertStringContainsString('text/html', $e->getMessage());
                }
            },
        );
    }

    public function test_close_is_idempotent_and_destructor_cleans_up(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\ndata: x\n\n");
                $server->waitForClientClose(10);

                return [];
            },
            function (string $baseUrl): void {
                $connection = (new CurlTransport)->open(new StreamRequest(
                    $baseUrl.'/events',
                    ['Accept' => 'text/event-stream'],
                    streamTimeoutSeconds: 10,
                ));

                $this->assertSame("data: x\n\n", $connection->read());

                $connection->close();
                $connection->close(); // second close must be a no-op

                $this->assertTrue($connection->isClosed());
                $this->assertFalse($connection->isCancelled());
                $this->assertNull($connection->read());
                $this->assertSame(200, $connection->statusCode());
                $this->assertSame(['text/event-stream'], $connection->headers()['content-type']);
            },
        );

        // Destructor path: dropping the last reference must close cleanly.
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\ndata: y\n\n");
                $server->waitForClientClose(10);

                return [];
            },
            function (string $baseUrl): void {
                $connection = (new CurlTransport)->open(new StreamRequest(
                    $baseUrl.'/events',
                    ['Accept' => 'text/event-stream'],
                    streamTimeoutSeconds: 10,
                ));
                $connection->read();
                $connection = null; // destructor must close without warnings
                $this->assertNull($connection);
            },
        );
    }
}
