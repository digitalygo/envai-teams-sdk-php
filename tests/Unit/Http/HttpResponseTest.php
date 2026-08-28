<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Http;

use Digitalygo\Envai\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase
{
    public function test_header_lookup_is_case_insensitive(): void
    {
        $response = new HttpResponse(200, ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertSame('application/json', $response->header('CONTENT-TYPE'));
    }

    public function test_duplicate_header_values_are_preserved(): void
    {
        $response = new HttpResponse(200, ['Set-Cookie' => ['a=1', 'b=2']]);

        $this->assertSame(['a=1', 'b=2'], $response->headerValues('set-cookie'));
        $this->assertSame('a=1', $response->header('set-cookie'));
    }

    public function test_missing_header_returns_null_and_empty_list(): void
    {
        $response = new HttpResponse(200);

        $this->assertNull($response->header('x-absent'));
        $this->assertSame([], $response->headerValues('x-absent'));
    }

    public function test_status_helpers(): void
    {
        $this->assertTrue((new HttpResponse(200))->isOk());
        $this->assertTrue((new HttpResponse(204))->isSuccess());
        $this->assertFalse((new HttpResponse(204))->isOk());
        $this->assertTrue((new HttpResponse(101))->isInformational());
        $this->assertTrue((new HttpResponse(302))->isRedirect());
        $this->assertTrue((new HttpResponse(404))->isClientError());
        $this->assertTrue((new HttpResponse(503))->isServerError());
        $this->assertFalse((new HttpResponse(200))->isServerError());
    }

    public function test_body_defaults_to_null(): void
    {
        $response = new HttpResponse(200);

        $this->assertNull($response->body);
        $this->assertSame('{"ok":true}', (new HttpResponse(200, [], '{"ok":true}'))->body);
    }

    public function test_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpResponse(42);
    }
}
