<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Acknowledgment for an accepted message: the message opened a turn
 * identified by `turn_id` (the orchestrator completion id `cmpl_<ulid>`).
 */
final readonly class ChatMessageAccepted implements ArrayHydratable
{
    /**
     * @param  list<string>|null  $attachmentIds
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $status,
        public string $turnId,
        public array $extra = [],
        public ?array $attachmentIds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'ChatMessageAccepted';

        return new self(
            status: Wire::string($data, 'status', $context),
            turnId: Wire::string($data, 'turn_id', $context),
            extra: Wire::extra($data, ['status', 'turn_id', 'attachment_ids']),
            attachmentIds: array_key_exists('attachment_ids', $data) ? Wire::stringList($data, 'attachment_ids', $context) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'status' => $this->status,
            'turn_id' => $this->turnId,
        ], $this->extra, $this->attachmentIds === null ? [] : ['attachment_ids' => $this->attachmentIds]);
    }
}
