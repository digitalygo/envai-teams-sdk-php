<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class FileOutcome implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $fileId,
        public string $status,
        public ?string $errorCode,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            fileId: Wire::string($data, 'file_id', self::class),
            status: Wire::string($data, 'status', self::class),
            errorCode: Wire::nullableString($data, 'error_code', self::class),
            extra: Wire::extra($data, ['file_id', 'status', 'error_code']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'file_id' => $this->fileId,
            'status' => $this->status,
            'error_code' => $this->errorCode,
        ]);
    }
}
