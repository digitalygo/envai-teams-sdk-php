<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Curl;

use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\Curl\CurlTransport;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;

final class CurlTransportTest extends CurlFunctionalTestCase
{
    public function test_get_returns_status_headers_and_json_body(): void
    {
        $response = null;

        $summary = $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $json = '{"status":"ok"}';
                $server->respond(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Trace: abc123\r\nContent-Length: ".strlen($json)."\r\n\r\n".$json,
                );

                return ['request_line' => explode("\r\n", $request)[0] ?? ''];
            },
            function (string $baseUrl) use (&$response): void {
                $response = (new CurlTransport)->send(HttpRequest::get($baseUrl.'/health', ['Accept' => 'application/json']));
            },
        );

        $this->assertStringStartsWith('GET /health HTTP/1.1', $summary['request_line'] ?? '');
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
        $this->assertSame(['application/json'], $response->headers['content-type']);
        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertSame('abc123', $response->header('x-trace'));
    }

    public function test_repeated_set_cookie_headers_are_preserved(): void
    {
        $response = null;

        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond(
                    "HTTP/1.1 200 OK\r\nSet-Cookie: a=1; Path=/\r\nSet-Cookie: b=2; Path=/\r\nContent-Length: 0\r\n\r\n",
                );

                return [];
            },
            function (string $baseUrl) use (&$response): void {
                $response = (new CurlTransport)->send(HttpRequest::get($baseUrl.'/cookies'));
            },
        );

        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $response->headers['set-cookie']);
        $this->assertSame('a=1; Path=/', $response->header('set-cookie'));
        $this->assertSame('', $response->body);
    }

    public function test_post_with_string_body_and_auth_header_is_echoed(): void
    {
        $response = null;

        $summary = $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $headerEnd = strpos($request, "\r\n\r\n");
                $headerBlock = substr($request, 0, $headerEnd);
                $body = substr($request, $headerEnd + 4);

                $server->respond(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body,
                );

                return [
                    'request_line' => explode("\r\n", $request)[0] ?? '',
                    'content_type' => $this->headerValue($headerBlock, 'content-type'),
                    'authorization' => $this->headerValue($headerBlock, 'authorization'),
                    'body' => $body,
                ];
            },
            function (string $baseUrl) use (&$response): void {
                $response = (new CurlTransport)->send(HttpRequest::post(
                    $baseUrl.'/echo',
                    ['Content-Type' => 'application/json', 'Authorization' => 'Bearer test-token'],
                    '{"name":"alice"}',
                ));
            },
        );

        $this->assertStringStartsWith('POST /echo HTTP/1.1', $summary['request_line'] ?? '');
        $this->assertSame('application/json', $summary['content_type']);
        $this->assertSame('Bearer test-token', $summary['authorization']);
        $this->assertSame('{"name":"alice"}', $summary['body']);
        $this->assertSame(200, $response->status);
        $this->assertSame('{"name":"alice"}', $response->body);
    }

    public function test_post_multipart_receives_fields_and_file_parts(): void
    {
        $response = null;
        $sentBody = null;

        $summary = $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $headerEnd = strpos($request, "\r\n\r\n");
                $headerBlock = substr($request, 0, $headerEnd);
                $body = substr($request, $headerEnd + 4);

                $boundary = $this->boundaryOf($headerBlock);
                $lowerBody = strtolower($body);

                $server->respond("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

                return [
                    'content_type' => $this->headerValue($headerBlock, 'content-type'),
                    'boundary' => $boundary,
                    'has_boundary_in_body' => $boundary !== null && str_contains($body, '--'.$boundary),
                    'has_closing_boundary' => $boundary !== null && str_contains($body, '--'.$boundary.'--'),
                    'has_field' => str_contains($lowerBody, 'name="caption"'),
                    'has_field_value' => str_contains($body, "name=\"caption\"\r\n\r\nphoto"),
                    'has_file_part' => str_contains($lowerBody, 'name="upload"; filename="photo.png"'),
                    'has_file_mime' => str_contains($lowerBody, 'content-type: image/png'),
                    'has_file_bytes' => str_contains($body, 'binary-data'),
                ];
            },
            function (string $baseUrl) use (&$response, &$sentBody): void {
                $sentBody = new MultipartBody([
                    new MultipartField('caption', 'photo'),
                    MultipartFile::fromContents('upload', 'binary-data', 'photo.png', 'image/png'),
                ]);

                $response = (new CurlTransport)->send(HttpRequest::post(
                    $baseUrl.'/uploads',
                    ['Content-Type' => $sentBody->contentType()],
                    $sentBody,
                ));
            },
        );

        $this->assertSame(200, $response->status);
        $this->assertNotNull($summary['boundary'], 'The server must receive a multipart boundary.');
        $this->assertSame('multipart/form-data; boundary='.$summary['boundary'], $summary['content_type']);
        // The transport drops the caller Content-Type for multipart so cURL
        // owns the boundary: it must differ from ours and match the body.
        $this->assertNotSame($sentBody->boundary, $summary['boundary']);
        $this->assertTrue($summary['has_boundary_in_body']);
        $this->assertTrue($summary['has_closing_boundary']);
        $this->assertTrue($summary['has_field']);
        $this->assertTrue($summary['has_field_value']);
        $this->assertTrue($summary['has_file_part']);
        $this->assertTrue($summary['has_file_mime']);
        $this->assertTrue($summary['has_file_bytes']);
    }

    public function test_streaming_download_writes_chunks_to_sink_and_leaves_body_null(): void
    {
        $payload = str_repeat('0123456789abcdef', 65536); // 1 MiB
        $chunkSize = 64 * 1024;

        $response = null;
        $received = null;
        $writeCount = 0;
        $completed = false;

        $this->withServer(
            function (string $request, TestHttpServer $server) use ($payload, $chunkSize): array {
                $server->respond(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: ".strlen($payload)."\r\n\r\n",
                );

                for ($offset = 0; $offset < strlen($payload); $offset += $chunkSize) {
                    $server->respond(substr($payload, $offset, $chunkSize));
                    usleep(2000);
                }

                return ['request_line' => explode("\r\n", $request)[0] ?? ''];
            },
            function (string $baseUrl) use (&$response, &$received, &$writeCount, &$completed): void {
                $resource = fopen('php://temp', 'w+b');
                $this->assertNotFalse($resource);

                $sink = new class($resource) implements ResponseBodySink
                {
                    public int $writeCount = 0;

                    public bool $completed = false;

                    /** @param  resource  $resource */
                    public function __construct(private $resource) {}

                    public function write(string $chunk): void
                    {
                        $this->writeCount++;
                        fwrite($this->resource, $chunk);
                    }

                    public function complete(): void
                    {
                        $this->completed = true;
                        fflush($this->resource);
                    }
                };

                $response = (new CurlTransport)->send(HttpRequest::get($baseUrl.'/download')->withSink($sink));
                rewind($resource);
                $received = stream_get_contents($resource);
                $writeCount = $sink->writeCount;
                $completed = $sink->completed;
                fclose($resource);
            },
        );

        $this->assertSame(200, $response->status);
        $this->assertNull($response->body);
        $this->assertSame($payload, $received);
        $this->assertTrue($completed);
        $this->assertGreaterThan(1, $writeCount);
    }

    public function test_connection_refused_throws_transport_exception_with_curl_errno(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $address = stream_socket_get_name($probe, false);
        fclose($probe); // free the port: nothing is listening there anymore

        try {
            (new CurlTransport)->send(HttpRequest::get('http://'.$address.'/unreachable'));
            $this->fail('Expected TransportException for a refused connection.');
        } catch (TransportException $e) {
            $this->assertSame(7, $e->context()['curl_errno'] ?? null);
            $this->assertStringContainsString('cURL transport error 7', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_request_timeout_throws_transport_exception(): void
    {
        $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->waitForClientClose(8); // never respond

                return [];
            },
            function (string $baseUrl): void {
                try {
                    (new CurlTransport)->send(HttpRequest::get($baseUrl.'/slow')->withTimeouts(2, 1));
                    $this->fail('Expected TransportException for a request timeout.');
                } catch (TransportException $e) {
                    $this->assertSame(28, $e->context()['curl_errno'] ?? null);
                    $this->assertStringContainsString('cURL transport error 28', $e->getMessage());
                    $this->assertStringContainsString('request timeout 1 seconds', $e->getMessage());
                }
            },
        );
    }

    private function headerValue(string $headerBlock, string $name): ?string
    {
        $match = preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/mi', $headerBlock, $m);

        return $match === 1 ? trim($m[1]) : null;
    }

    private function boundaryOf(string $headerBlock): ?string
    {
        $match = preg_match('/^content-type:\s*multipart\/form-data;\s*boundary=([^\s;]+)/mi', $headerBlock, $m);

        return $match === 1 ? $m[1] : null;
    }
}
