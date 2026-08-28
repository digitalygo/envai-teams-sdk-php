<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Curl;

/**
 * Collects response header lines delivered by cURL's header callback.
 *
 * Interim (1xx) header blocks are discarded: the final non-1xx block wins,
 * mirroring how curl_getinfo(CURLINFO_RESPONSE_CODE) reports the final status.
 * Header names are normalized to lowercase and duplicate values are preserved
 * as a list. isComplete() flips to true once the final header block has been
 * terminated by its blank separator line.
 */
final class CurlHeaderCollector
{
    /**
     * @var array<string, list<string>>
     */
    private array $headers = [];

    private ?string $lastHeaderName = null;

    private ?int $blockStatus = null;

    private bool $complete = false;

    /**
     * Feeds one raw header line, including the blank separator line that ends
     * a header block. cURL delivers lines with their trailing CRLF.
     */
    public function feed(string $line): void
    {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            $this->complete = $this->blockStatus === null || $this->blockStatus >= 200;

            return;
        }

        if ($line[0] === ' ' || $line[0] === "\t") {
            $this->fold($line);

            return;
        }

        $colon = strpos($line, ':');

        if ($colon === false) {
            $this->beginBlock($line);

            return;
        }

        $name = strtolower(rtrim(substr($line, 0, $colon)));
        $value = ltrim(substr($line, $colon + 1));

        if ($name === '') {
            return;
        }

        $this->headers[$name][] = $value;
        $this->lastHeaderName = $name;
        $this->complete = false;
    }

    /**
     * Returns the collected headers of the final response block.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Returns the status code of the current header block, when known.
     */
    public function status(): ?int
    {
        return $this->blockStatus;
    }

    private function beginBlock(string $statusLine): void
    {
        $this->headers = [];
        $this->lastHeaderName = null;
        $this->blockStatus = self::parseStatus($statusLine);
        $this->complete = false;
    }

    private function fold(string $line): void
    {
        if ($this->lastHeaderName === null) {
            return;
        }

        $values = $this->headers[$this->lastHeaderName];

        if ($values === []) {
            return;
        }

        $values[count($values) - 1] .= ' '.trim($line);
        $this->headers[$this->lastHeaderName] = $values;
    }

    private static function parseStatus(string $line): ?int
    {
        $parts = explode(' ', $line, 3);

        if (count($parts) < 2 || preg_match('/^HTTP\/\d(?:\.\d)?$/i', $parts[0]) !== 1) {
            return null;
        }

        $status = (int) $parts[1];

        return $status > 0 ? $status : null;
    }
}
