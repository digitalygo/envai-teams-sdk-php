<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Sse;

/**
 * Incremental Server-Sent Events parser over a byte stream.
 *
 * The parser follows the SSE grammar: lines end with "\r\n", "\n" or "\r";
 * a frame boundary is the blank line; `data:` lines accumulate and are
 * joined with "\n" in arrival order; `event:` keeps the last value; comment
 * lines (starting with ":") and unknown fields are ignored; `id:` and
 * `retry:` are recognized but carry no meaning for this protocol.
 *
 * Feed network chunks of any size with feed() — a single line may be split
 * across chunks, one chunk may contain many frames, and UTF-8 sequences may
 * straddle chunk boundaries because bytes simply accumulate. nextFrame()
 * returns a complete frame or null while the buffered bytes do not yet end
 * a frame; a trailing line without its terminating newline is never flushed.
 *
 * A leading UTF-8 BOM is stripped once, also when it arrives split across
 * the first chunks.
 */
final class SseParser
{
    private string $buffer = '';

    private ?string $eventType = null;

    /**
     * @var list<string>
     */
    private array $dataLines = [];

    private bool $lineConsumed = false;

    /**
     * Appends raw bytes to the internal buffer.
     */
    public function feed(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $this->buffer .= $bytes;
    }

    /**
     * Returns the next complete frame, or null when the buffered bytes do
     * not yet contain one.
     */
    public function nextFrame(): ?SseFrame
    {
        while (true) {
            $line = $this->consumeLine();

            if ($line === null) {
                return null;
            }

            if ($line === '') {
                if ($this->eventType === null && $this->dataLines === []) {
                    continue;
                }

                $frame = new SseFrame($this->eventType, implode("\n", $this->dataLines));

                $this->eventType = null;
                $this->dataLines = [];

                return $frame;
            }

            $this->dispatchLine($line);
        }
    }

    /** Resolve an EOF trailing bare CR without fabricating a missing frame boundary. */
    public function finishInput(): void
    {
        if (str_ends_with($this->buffer, "\r")) {
            $this->buffer .= "\n";
        }
    }

    public function hasIncompleteFrame(): bool
    {
        return $this->buffer !== '' || $this->eventType !== null || $this->dataLines !== [];
    }

    /**
     * Extracts one line (without its terminator) or null when no complete
     * line is buffered. A trailing "\r" is held back until the next chunk
     * decides whether it is a bare "\r" terminator or part of "\r\n".
     */
    private function consumeLine(): ?string
    {
        if (! $this->lineConsumed && strlen($this->buffer) >= 3 && str_starts_with($this->buffer, "\xEF\xBB\xBF")) {
            $this->buffer = substr($this->buffer, 3);
        }

        $length = strlen($this->buffer);

        for ($i = 0; $i < $length; $i++) {
            $char = $this->buffer[$i];

            if ($char === "\n") {
                $line = substr($this->buffer, 0, $i);
                $this->buffer = substr($this->buffer, $i + 1);
                $this->lineConsumed = true;

                return $line;
            }

            if ($char === "\r") {
                if ($i === $length - 1) {
                    return null;
                }

                $line = substr($this->buffer, 0, $i);

                $this->buffer = $this->buffer[$i + 1] === "\n"
                    ? substr($this->buffer, $i + 2)
                    : substr($this->buffer, $i + 1);

                $this->lineConsumed = true;

                return $line;
            }
        }

        return null;
    }

    /**
     * Applies one non-blank line to the accumulating frame state.
     */
    private function dispatchLine(string $line): void
    {
        if ($line === '' || $line[0] === ':') {
            return;
        }

        $colon = strpos($line, ':');

        if ($colon === false) {
            $name = $line;
            $value = '';
        } else {
            $name = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);

            if ($value !== '' && $value[0] === ' ') {
                $value = substr($value, 1);
            }
        }

        match ($name) {
            'event' => $this->eventType = $value,
            'data' => $this->dataLines[] = $value,
            default => null,
        };
    }
}
