<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\WorkspaceFile;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Descriptor of a single child of a directory listing. `size` is the size in
 * bytes as reported by the file service, `modified_at` stays an ISO 8601
 * string (wire-faithful) and `mime_type` is always null for directories.
 */
final readonly class WorkspaceFileEntry implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $path,
        public string $name,
        public WorkspaceFileType $type,
        public int $size,
        public ?string $modifiedAt,
        public ?string $mimeType,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'WorkspaceFileEntry';

        return new self(
            path: Wire::string($data, 'path', $context),
            name: Wire::string($data, 'name', $context),
            type: Wire::enum($data, 'type', WorkspaceFileType::class, $context),
            size: Wire::int($data, 'size', $context),
            modifiedAt: Wire::nullableString($data, 'modified_at', $context),
            mimeType: Wire::nullableString($data, 'mime_type', $context),
            extra: Wire::extra($data, ['path', 'name', 'type', 'size', 'modified_at', 'mime_type']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'path' => $this->path,
            'name' => $this->name,
            'type' => $this->type->value,
            'size' => $this->size,
            'modified_at' => $this->modifiedAt,
            'mime_type' => $this->mimeType,
        ];

        return array_merge($payload, $this->extra);
    }
}
