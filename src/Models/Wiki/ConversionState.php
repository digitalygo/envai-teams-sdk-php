<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class ConversionState implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $status,
        public array $extra = [],
        public ?string $errorCode = null,
        public ?bool $recoverable = null,
        public ?string $nextRetryAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            status: Wire::string($data, 'status', self::class),
            extra: Wire::extra($data, ['status', 'error_code', 'recoverable', 'next_retry_at']),
            errorCode: Wire::nullableString($data, 'error_code', self::class),
            recoverable: array_key_exists('recoverable', $data) ? Wire::bool($data, 'recoverable', self::class) : null,
            nextRetryAt: Wire::nullableString($data, 'next_retry_at', self::class),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = array_merge($this->extra, [
            'status' => $this->status,
        ]);
        if ($this->recoverable !== null || $this->errorCode !== null || $this->nextRetryAt !== null) {
            $data['error_code'] = $this->errorCode;
            $data['next_retry_at'] = $this->nextRetryAt;
        }
        if ($this->recoverable !== null) {
            $data['recoverable'] = $this->recoverable;
        }

        return $data;
    }
}
