<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class RejectOversizedWebhookBody
{
    public function __construct(private int $maxBytes)
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('The webhook body size limit must not be negative.');
        }
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength === null) {
            $serverContentLength = $request->server('CONTENT_LENGTH');
            $contentLength = is_string($serverContentLength) ? $serverContentLength : null;
        }

        if ($this->exceedsLimit($contentLength)) {
            return new Response('', 413);
        }

        return $next($request);
    }

    private function exceedsLimit(?string $contentLength): bool
    {
        if ($contentLength === null || preg_match('/\A[0-9]+\z/D', $contentLength) !== 1) {
            return false;
        }

        $length = ltrim($contentLength, '0');
        $length = $length === '' ? '0' : $length;
        $limit = (string) $this->maxBytes;

        return strlen($length) > strlen($limit)
            || (strlen($length) === strlen($limit) && strcmp($length, $limit) > 0);
    }
}
