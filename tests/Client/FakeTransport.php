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

    /** @var list<HttpRequest> */
    public array $preflightRequests = [];

    public function __construct(private bool $negotiateContract = true)
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
        $selector = $request->headers['x-envai-contract-version'][0] ?? null;
        $queued = $this->queue[0] ?? null;
        $queuedPayload = $queued instanceof HttpResponse ? json_decode((string) $queued->body, true) : null;
        if ($this->negotiateContract && str_ends_with($request->uri, '/atp/v3/capabilities') && ! isset($queuedPayload['protocol_version'])) {
            $this->preflightRequests[] = $request;

            return new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], json_encode(['protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3], 'legacy_v2_mode' => 'historical_reads_replay_only', 'features' => [], 'families' => [], 'event_types' => [], 'tool_kinds' => []]));
        }
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        if ($next === null) {
            throw new \LogicException('FakeTransport queue exhausted.');
        }

        if ($this->negotiateContract && $selector !== null && $next->status >= 200 && $next->status < 300) {
            return new HttpResponse($next->status, [...$next->headers, 'x-envai-contract-version' => [$selector]], $next->body);
        }

        return $next;
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        $this->streamRequests[] = $request;

        return $this->connection;
    }
}
