<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Http;

use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\RetrySafety;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    public function test_named_constructors_set_method(): void
    {
        $this->assertSame('GET', HttpRequest::get('https://api.example/up')->method);
        $this->assertSame('POST', HttpRequest::post('https://api.example/chats')->method);
        $this->assertSame('PUT', HttpRequest::put('https://api.example/agents/x')->method);
        $this->assertSame('DELETE', HttpRequest::delete('https://api.example/tools/x')->method);
    }

    public function test_headers_are_normalized_to_lowercase_lists(): void
    {
        $request = HttpRequest::get('https://api.example/up', [
            'Accept' => 'application/json',
            'X-Trace' => ['a', 'b'],
        ]);

        $this->assertSame(['application/json'], $request->headers['accept']);
        $this->assertSame(['a', 'b'], $request->headers['x-trace']);
    }

    public function test_with_header_appends_to_the_header_list(): void
    {
        $request = HttpRequest::get('https://api.example/up')
            ->withHeader('X-Trace', 'first')
            ->withHeader('x-trace', 'second');

        $this->assertSame(['first', 'second'], $request->headers['x-trace']);
        $this->assertSame(['first', 'second'], $request->headers['X-Trace'] ?? $request->headers['x-trace']);
    }

    public function test_with_sink_attaches_the_sink(): void
    {
        $sink = new class implements ResponseBodySink
        {
            public function write(string $chunk): void {}

            public function complete(): void {}
        };

        $request = HttpRequest::get('https://api.example/download')->withSink($sink);

        $this->assertSame($sink, $request->sink);
    }

    public function test_with_auth_and_retry_safety_override_defaults(): void
    {
        $request = HttpRequest::post('https://api.example/chats/x/messages')
            ->withAuth(AuthenticationMode::None)
            ->withRetrySafety(RetrySafety::NeverRetry);

        $this->assertSame(AuthenticationMode::None, $request->auth);
        $this->assertSame(RetrySafety::NeverRetry, $request->retrySafety);
    }

    public function test_defaults(): void
    {
        $request = HttpRequest::get('https://api.example/up');

        $this->assertSame(10, $request->connectTimeoutSeconds);
        $this->assertSame(60, $request->requestTimeoutSeconds);
        $this->assertSame(AuthenticationMode::Bearer, $request->auth);
        $this->assertSame(RetrySafety::Safe, $request->retrySafety);
        $this->assertNull($request->body);
        $this->assertNull($request->sink);
    }

    public function test_rejects_empty_uri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpRequest::get('');
    }

    public function test_rejects_non_positive_request_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpRequest('GET', 'https://api.example/up', requestTimeoutSeconds: 0);
    }
}
