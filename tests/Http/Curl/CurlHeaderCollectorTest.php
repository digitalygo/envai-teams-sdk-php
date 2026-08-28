<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Curl;

use Digitalygo\Envai\Http\Curl\CurlHeaderCollector;
use PHPUnit\Framework\TestCase;

final class CurlHeaderCollectorTest extends TestCase
{
    public function test_collects_headers_with_lowercase_names(): void
    {
        $collector = new CurlHeaderCollector;

        $collector->feed('HTTP/1.1 200 OK');
        $collector->feed('Content-Type: application/json');
        $collector->feed('X-Trace: abc');
        $collector->feed('');

        $this->assertSame(['application/json'], $collector->headers()['content-type']);
        $this->assertSame(['abc'], $collector->headers()['x-trace']);
        $this->assertTrue($collector->isComplete());
        $this->assertSame(200, $collector->status());
    }

    public function test_duplicate_headers_are_preserved_as_a_list(): void
    {
        $collector = new CurlHeaderCollector;

        $collector->feed('HTTP/1.1 200 OK');
        $collector->feed('Set-Cookie: a=1; Path=/');
        $collector->feed('Set-Cookie: b=2; Path=/');
        $collector->feed('');

        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $collector->headers()['set-cookie']);
    }

    public function test_interim_100_block_is_discarded(): void
    {
        $collector = new CurlHeaderCollector;

        $collector->feed('HTTP/1.1 100 Continue');
        $collector->feed('');
        $this->assertFalse($collector->isComplete());
        $this->assertSame([], $collector->headers());

        $collector->feed('HTTP/1.1 200 OK');
        $collector->feed('Content-Type: text/event-stream');
        $collector->feed('');
        $this->assertTrue($collector->isComplete());
        $this->assertSame(['text/event-stream'], $collector->headers()['content-type']);
    }

    public function test_continuation_line_is_folded_into_the_previous_header(): void
    {
        $collector = new CurlHeaderCollector;

        $collector->feed('HTTP/1.1 200 OK');
        $collector->feed('X-Long: part one');
        $collector->feed("\tpart two");
        $collector->feed('');

        $this->assertSame(['part one part two'], $collector->headers()['x-long']);
    }

    public function test_http_2_status_line_is_parsed(): void
    {
        $collector = new CurlHeaderCollector;

        $collector->feed('HTTP/2 204');
        $collector->feed('');

        $this->assertTrue($collector->isComplete());
        $this->assertSame(204, $collector->status());
    }

    public function test_is_complete_stays_false_without_the_blank_separator(): void
    {
        $collector = new CurlHeaderCollector;

        $collector->feed('HTTP/1.1 200 OK');
        $collector->feed('Content-Type: application/json');

        $this->assertFalse($collector->isComplete());
    }
}
