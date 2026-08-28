<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\StreamRequest;

/**
 * Scripted HttpTransport and EventStreamTransport for the client service
 * tests: serves a queue of responses or exceptions, records every request
 * and stream request it receives, and returns a scripted stream connection.
 */
final class FakeTransport implements EventStreamTransport, HttpTransport
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
     * @var list<StreamRequest>
     */
    public array $streamRequests = [];

    public EventStreamConnection $connection;

    public function __construct()
    {
        $this->connection = new FakeEventStreamConnection;
    }

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

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        if ($next === null) {
            throw new \LogicException('FakeTransport queue exhausted.');
        }

        return $next;
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        $this->streamRequests[] = $request;

        return $this->connection;
    }
}
