<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;

/**
 * Scripted HttpTransport for tests: serves a queue of responses or exceptions
 * and records every request it receives.
 */
final class FakeTransport implements HttpTransport
{
    /**
     * @var list<HttpResponse|\Throwable>
     */
    private array $queue = [];

    /**
     * @var list<HttpRequest>
     */
    public array $requests = [];

    /**
     * @var list<float> Microtime of each send() call, for delay assertions.
     */
    public array $sendTimes = [];

    public function queueResponse(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function queueException(\Throwable $exception): void
    {
        $this->queue[] = $exception;
    }

    public function queueCount(): int
    {
        return count($this->queue);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $this->sendTimes[] = microtime(true);

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        if ($next === null) {
            throw new \LogicException('FakeTransport queue exhausted.');
        }

        return $next;
    }
}
