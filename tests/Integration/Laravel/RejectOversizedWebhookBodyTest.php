<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Http\Middleware\RejectOversizedWebhookBody;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class RejectOversizedWebhookBodyTest extends IntegrationTestCase
{
    public function test_content_length_below_limit_passes_to_the_next_middleware(): void
    {
        $request = Request::create('/webhooks', 'POST', content: 'body');
        $request->headers->set('Content-Length', '4');
        $nextCalls = 0;

        $response = (new RejectOversizedWebhookBody(5))->handle($request, function (Request $request) use (&$nextCalls): Response {
            $nextCalls++;

            return new Response('next');
        });

        $this->assertSame(1, $nextCalls);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('next', $response->getContent());
    }

    public function test_content_length_above_limit_returns_an_empty_413_response_before_next_middleware(): void
    {
        $request = Request::create('/webhooks', 'POST', content: 'body');
        $request->headers->set('Content-Length', '6');
        $nextCalls = 0;

        $response = (new RejectOversizedWebhookBody(5))->handle($request, function (Request $request) use (&$nextCalls): Response {
            $nextCalls++;

            return new Response('next');
        });

        $this->assertSame(0, $nextCalls);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    /** @return array<string, array{?string}> */
    public static function contentLengthsThatPass(): array
    {
        return [
            'missing' => [null],
            'non-numeric' => ['not-a-number'],
        ];
    }

    #[DataProvider('contentLengthsThatPass')]
    public function test_missing_or_non_numeric_content_length_passes(?string $contentLength): void
    {
        $request = Request::create('/webhooks', 'POST', content: 'body');

        if ($contentLength !== null) {
            $request->headers->set('Content-Length', $contentLength);
        }

        $nextCalls = 0;
        $response = (new RejectOversizedWebhookBody(1))->handle($request, function (Request $request) use (&$nextCalls): Response {
            $nextCalls++;

            return new Response('next');
        });

        $this->assertSame(1, $nextCalls);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('next', $response->getContent());
    }
}
