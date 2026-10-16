<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Usage\UsageCost;

final readonly class ChatAttachment implements ArrayHydratable
{
    /** @param array<string, mixed>|null $derived
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $attachmentId,
        public string $workspaceId,
        public string $chatId,
        public string $originalName,
        public string $mimeType,
        public int $byteSize,
        public string $lifecycle,
        public int $revision,
        public AttachmentConversion $conversion,
        public ?array $derived,
        public string $expiresAt,
        public ?string $attachedAt,
        public UsageCost $usageCost,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            attachmentId: Wire::string($data, 'attachment_id', self::class),
            workspaceId: Wire::string($data, 'workspace_id', self::class),
            chatId: Wire::string($data, 'chat_id', self::class),
            originalName: Wire::string($data, 'original_name', self::class),
            mimeType: Wire::string($data, 'mime_type', self::class),
            byteSize: Wire::int($data, 'byte_size', self::class),
            lifecycle: Wire::string($data, 'lifecycle', self::class),
            revision: Wire::int($data, 'revision', self::class),
            conversion: AttachmentConversion::fromArray(Wire::objectArray($data, 'conversion', self::class)),
            derived: ($data['derived'] ?? null) === null ? null : Wire::objectArray($data, 'derived', self::class),
            expiresAt: Wire::string($data, 'expires_at', self::class),
            attachedAt: Wire::nullableString($data, 'attached_at', self::class),
            usageCost: UsageCost::fromArray(Wire::objectArray($data, 'usage_cost', self::class)),
            extra: Wire::extra($data, ['attachment_id', 'workspace_id', 'chat_id', 'original_name', 'mime_type', 'byte_size', 'lifecycle', 'revision', 'conversion', 'derived', 'expires_at', 'attached_at', 'usage_cost']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'attachment_id' => $this->attachmentId,
            'workspace_id' => $this->workspaceId,
            'chat_id' => $this->chatId,
            'original_name' => $this->originalName,
            'mime_type' => $this->mimeType,
            'byte_size' => $this->byteSize,
            'lifecycle' => $this->lifecycle,
            'revision' => $this->revision,
            'conversion' => $this->conversion->toArray(),
            'derived' => $this->derived,
            'expires_at' => $this->expiresAt,
            'attached_at' => $this->attachedAt,
            'usage_cost' => $this->usageCost->toArray(),
        ]);
    }
}
