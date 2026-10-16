<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class AttachmentConversion implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(public string $status, public ?string $errorCode, public bool $recoverable, public ?string $nextRetryAt, public bool $retryable, public array $extra = []) {}

    public static function fromArray(array $data): static
    {
        return new self(Wire::string($data, 'status', self::class), Wire::nullableString($data, 'error_code', self::class), Wire::bool($data, 'recoverable', self::class), Wire::nullableString($data, 'next_retry_at', self::class), Wire::bool($data, 'retryable', self::class), Wire::extra($data, ['status', 'error_code', 'recoverable', 'next_retry_at', 'retryable']));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, ['status' => $this->status, 'error_code' => $this->errorCode, 'recoverable' => $this->recoverable, 'next_retry_at' => $this->nextRetryAt, 'retryable' => $this->retryable]);
    }
}
