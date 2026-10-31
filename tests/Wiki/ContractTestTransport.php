<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\StreamRequest;

final class ContractTestTransport implements EventStreamTransport, HttpTransport
{
    public array $requests = [];

    public ?\Closure $onSend = null;

    public array $streamRequests = [];

    public array $responses = [];

    public array $connections = [];

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->onSend !== null) {
            ($this->onSend)($request);
        }
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next ?? throw new \LogicException('No scripted response.');
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        $this->streamRequests[] = $request;
        $next = array_shift($this->connections);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next ?? throw new \LogicException('No scripted connection.');
    }
}
