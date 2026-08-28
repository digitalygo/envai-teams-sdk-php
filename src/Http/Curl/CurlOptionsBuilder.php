<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Curl;

use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\StreamRequest;

/**
 * Builds the static cURL option sets for plain requests and SSE streams.
 *
 * Transfer callbacks (header, body, progress) are attached by the transport
 * and the stream connection because they capture per-transfer state; every
 * option that can be expressed without state lives here so the mapping stays
 * unit testable.
 */
final class CurlOptionsBuilder
{
    private function __construct() {}

    /**
     * Builds the options for a one-shot HTTP request.
     *
     * For multipart bodies the caller supplied Content-Type header is dropped
     * on purpose: cURL generates the multipart body itself and must also own
     * the boundary it announces, otherwise the header and the encoded body
     * would disagree.
     *
     * @return array<int|string, mixed>
     */
    public static function forRequest(HttpRequest $request): array
    {
        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutSeconds * 1000,
            CURLOPT_TIMEOUT_MS => $request->requestTimeoutSeconds * 1000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        if ($request->body instanceof MultipartBody) {
            $options[CURLOPT_POSTFIELDS] = self::multipartPostFields($request->body);
            $headers = $request->headers;
            unset($headers['content-type']);
        } elseif (is_string($request->body)) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
            $headers = $request->headers;
        } else {
            $headers = $request->headers;
        }

        $options[CURLOPT_HTTPHEADER] = self::headerLines($headers);

        return $options;
    }

    /**
     * Builds the options for an SSE stream.
     *
     * CURLOPT_TIMEOUT_MS is set only when a total stream deadline exists; a
     * long lived stream must never be killed by a request timeout.
     *
     * @return array<int|string, mixed>
     */
    public static function forStream(StreamRequest $request): array
    {
        $options = [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutSeconds * 1000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        if ($request->streamTimeoutSeconds !== null) {
            $options[CURLOPT_TIMEOUT_MS] = $request->streamTimeoutSeconds * 1000;
        }

        $options[CURLOPT_HTTPHEADER] = self::headerLines($request->headers);

        return $options;
    }

    /**
     * Converts the ordered multipart parts into a CURLOPT_POSTFIELDS array.
     *
     * File parts become CURLFile (streamed from disk) or CURLStringFile
     * (in-memory) values, plain fields become scalar strings. cURL owns the
     * boundary and the Content-Type header for this representation. Note that
     * duplicate part names collapse because array keys must be unique.
     *
     * @return array<string, string|\CURLFile|\CURLStringFile>
     */
    public static function multipartPostFields(MultipartBody $body): array
    {
        $fields = [];

        foreach ($body->parts as $part) {
            if ($part instanceof MultipartField) {
                $fields[$part->name] = $part->value;
            } elseif ($part->path !== null) {
                $fields[$part->name] = new \CURLFile($part->path, $part->mimeType, $part->filename);
            } else {
                $fields[$part->name] = new \CURLStringFile($part->contents(), $part->filename, $part->mimeType);
            }
        }

        return $fields;
    }

    /**
     * Serializes a normalized header map into "name: value" cURL header lines.
     *
     * The "Expect:" line with an empty value disables "Expect: 100-continue",
     * which would stall transfers against servers that never answer the
     * interim request.
     *
     * @param  array<string, list<string>>  $headers
     * @return list<string>
     */
    public static function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name.': '.$value;
            }
        }

        $lines[] = 'Expect:';

        return $lines;
    }

    /**
     * Strips userinfo, query and fragment from a URI for error messages.
     *
     * Error messages must never leak credentials; query strings and embedded
     * userinfo are treated as potentially sensitive and removed.
     */
    public static function sanitizeUri(string $uri): string
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            return $uri;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $scheme.'://'.$host.$port.$path;
    }
}
