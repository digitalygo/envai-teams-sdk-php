<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\WorkspaceFile;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Workspace file or directory representation returned inside the
 * `{data: ...}` wrapper. The first six fields form the descriptor; `content`
 * is added for files and `entries` for directories by the resource.
 *
 * Timestamps stay ISO 8601 strings (wire-faithful), never parsed into
 * DateTimeImmutable.
 */
final readonly class WorkspaceFile implements ArrayHydratable
{
    /**
     * @param  list<WorkspaceFileEntry>|null  $entries
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $path,
        public string $name,
        public WorkspaceFileType $type,
        public int $size,
        public ?string $modifiedAt,
        public ?string $mimeType,
        public ?string $content = null,
        public ?array $entries = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'WorkspaceFile';

        $entries = null;

        if (array_key_exists('entries', $data) && $data['entries'] !== null) {
            if (! is_array($data['entries'])) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "entries" must be an array, got %s.',
                    $context,
                    get_debug_type($data['entries']),
                ));
            }

            $list = [];

            foreach ($data['entries'] as $item) {
                if (! is_array($item)) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s payload field "entries" must be a list of entry objects, got a non-array entry.',
                        $context,
                    ));
                }

                $list[] = WorkspaceFileEntry::fromArray(Wire::stringKeyed($item, 'entries', $context));
            }

            $entries = $list;
        }

        return new self(
            path: Wire::string($data, 'path', $context),
            name: Wire::string($data, 'name', $context),
            type: Wire::enum($data, 'type', WorkspaceFileType::class, $context),
            size: Wire::int($data, 'size', $context),
            modifiedAt: Wire::nullableString($data, 'modified_at', $context),
            mimeType: Wire::nullableString($data, 'mime_type', $context),
            content: Wire::nullableString($data, 'content', $context),
            entries: $entries,
            extra: Wire::extra($data, ['path', 'name', 'type', 'size', 'modified_at', 'mime_type', 'content', 'entries']),
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

        if ($this->content !== null) {
            $payload['content'] = $this->content;
        }

        if ($this->entries !== null) {
            $payload['entries'] = array_map(static fn (WorkspaceFileEntry $entry): array => $entry->toArray(), $this->entries);
        }

        return array_merge($payload, $this->extra);
    }
}
