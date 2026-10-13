<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Sse\SseParser;

/** A finite legacy snapshot is fully validated before any bytes reach its consumer. */
final class LegacyReplayConnection implements EventStreamConnection
{
    private int $offset = 0;

    private bool $closed = false;

    private bool $cancelled = false;

    private function __construct(private string $bytes, private readonly EventStreamConnection $source, private readonly ?CancellationToken $token) {}

    public static function capture(EventStreamConnection $source, ?CancellationToken $token, int $maxBytes, string $chatId, int $after): self
    {
        $bytes = '';
        $parser = new SseParser;
        $decoder = new AtpEventDecoder(2);
        try {
            while (true) {
                $token?->throwIfCancelled();
                $chunk = $source->read();
                $token?->throwIfCancelled();
                if ($chunk === null) {
                    break;
                }
                if (strlen($chunk) > $maxBytes - strlen($bytes)) {
                    throw new ContractCompatibilityException('Legacy replay exceeds maxLegacyReplayBytes; no partial snapshot was delivered.');
                }
                $bytes .= $chunk;
                $parser->feed($chunk);
                self::validateFrames($parser, $decoder, $chatId, $after);
            }
            $parser->finishInput();
            self::validateFrames($parser, $decoder, $chatId, $after);
            if ($parser->hasIncompleteFrame()) {
                throw new ContractCompatibilityException('Legacy replay ended with an incomplete SSE frame.');
            }

            return new self($bytes, $source, $token);
        } finally {
            $source->close();
        }
    }

    private static function validateFrames(SseParser $parser, AtpEventDecoder $decoder, string $chatId, int &$cursor): void
    {
        while (($frame = $parser->nextFrame()) !== null) {
            $event = $decoder->decode($frame->data);
            if ($event instanceof ProtocolViolationEvent) {
                throw new ContractCompatibilityException('Legacy replay contains an invalid ATP v2 event; no partial snapshot was delivered.');
            }
            if ($event->envelope->chatId !== $chatId) {
                throw new ContractCompatibilityException('Legacy replay contains a foreign chat; no partial snapshot was delivered.');
            }
            if ($event->envelope->type !== 'ping' && $event->envelope->seq !== ++$cursor) {
                throw new ContractCompatibilityException('Legacy replay sequence is not contiguous after the requested cursor.');
            }
        }
    }

    public function read(int $maxBytes = 8192): ?string
    {
        $this->token?->throwIfCancelled();
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be positive.');
        }
        if ($this->closed || $this->offset >= strlen($this->bytes)) {
            return null;
        }
        $chunk = substr($this->bytes, $this->offset, $maxBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function statusCode(): int
    {
        return $this->source->statusCode();
    }

    public function headers(): array
    {
        return $this->source->headers();
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->close();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->bytes = '';
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
