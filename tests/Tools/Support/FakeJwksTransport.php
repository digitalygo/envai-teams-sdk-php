<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;

/**
 * HttpTransport fake serving a canned JWKS document. fetchCount and
 * lastRequest let tests observe the fetch behaviour of the JwksResolver.
 */
final class FakeJwksTransport implements HttpTransport
{
    public int $fetchCount = 0;

    public bool $fail = false;

    public int $status = 200;

    public ?string $rawBody = null;

    /**
     * @var array<string, mixed>
     */
    public array $document = ['keys' => []];

    public ?HttpRequest $lastRequest = null;

    public function send(HttpRequest $request): HttpResponse
    {
        $this->fetchCount++;
        $this->lastRequest = $request;

        if ($this->fail) {
            throw new TransportException('Simulated JWKS transport failure.');
        }

        $body = $this->rawBody ?? json_encode($this->document, JSON_THROW_ON_ERROR);

        return new HttpResponse($this->status, [], $body);
    }
}
