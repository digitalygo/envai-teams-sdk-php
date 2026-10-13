<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\UsageCost;

final readonly class WikiSource implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $fileId,
        public string $workspaceId,
        public string $originalName,
        public string $mimeType,
        public int $byteSize,
        public string $lifecycle,
        public int $sourceRevision,
        public ConversionState $conversion,
        public IndexingState $indexing,
        public ?string $canonicalRawPath,
        public BillingState $billing,
        public UsageCost $usageCost,
        public string $statusUrl,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            fileId: Wire::string($data, 'file_id', self::class),
            workspaceId: Wire::string($data, 'workspace_id', self::class),
            originalName: Wire::string($data, 'original_name', self::class),
            mimeType: Wire::string($data, 'mime_type', self::class),
            byteSize: Wire::int($data, 'byte_size', self::class),
            lifecycle: Wire::string($data, 'lifecycle', self::class),
            sourceRevision: Wire::int($data, 'source_revision', self::class),
            conversion: ConversionState::fromArray(Wire::objectArray($data, 'conversion', self::class)),
            indexing: IndexingState::fromArray(Wire::objectArray($data, 'indexing', self::class)),
            canonicalRawPath: Wire::nullableString($data, 'canonical_raw_path', self::class),
            billing: BillingState::fromArray(Wire::objectArray($data, 'billing', self::class)),
            usageCost: UsageCost::fromArray(Wire::objectArray($data, 'usage_cost', self::class)),
            statusUrl: Wire::string($data, 'status_url', self::class),
            extra: Wire::extra($data, ['file_id', 'workspace_id', 'original_name', 'mime_type', 'byte_size', 'lifecycle', 'source_revision', 'conversion', 'indexing', 'canonical_raw_path', 'billing', 'usage_cost', 'status_url']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'file_id' => $this->fileId,
            'workspace_id' => $this->workspaceId,
            'original_name' => $this->originalName,
            'mime_type' => $this->mimeType,
            'byte_size' => $this->byteSize,
            'lifecycle' => $this->lifecycle,
            'source_revision' => $this->sourceRevision,
            'conversion' => $this->conversion->toArray(),
            'indexing' => $this->indexing->toArray(),
            'canonical_raw_path' => $this->canonicalRawPath,
            'billing' => $this->billing->toArray(),
            'usage_cost' => $this->usageCost->toArray(),
            'status_url' => $this->statusUrl,
        ]);
    }
}
