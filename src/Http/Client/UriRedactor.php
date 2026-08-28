<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

/**
 * Redacts URIs for log messages: userinfo, query strings and fragments
 * are stripped, keeping only scheme://host[:port]/path.
 *
 * Signed artifact URLs (path, signature, expires) and provider webhook
 * callbacks (callback_token) travel as query parameters, so logging a
 * raw URI would persist capability tokens into the log stream. Every
 * URI passed to a logger must go through redact() first.
 */
final class UriRedactor
{
    public static function redact(string $uri): string
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            return $uri;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            // Relative reference: keep the path only (query and fragment
            // are still stripped).
            return $parts['path'] ?? '/';
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $scheme.'://'.$host.$port.$path;
    }
}
