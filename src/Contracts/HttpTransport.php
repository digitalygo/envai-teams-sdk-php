<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Contracts;

use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;

/**
 * Sends a single HTTP request and returns the full response.
 *
 * Deliberately not PSR-18: the SDK needs streaming downloads, configurable
 * timeouts, cancellation and retry classification that PSR-18 does not model.
 */
interface HttpTransport
{
    public function send(HttpRequest $request): HttpResponse;
}
