<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Fork acknowledgement carrying the source and destination chat ids.
 */
final readonly class ChatForked implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $sourceChatId,
        public string $destinationChatId,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ChatForked';

        return new self(
            sourceChatId: Wire::string($data, 'source_chat_id', $context),
            destinationChatId: Wire::string($data, 'destination_chat_id', $context),
            extra: Wire::extra($data, ['source_chat_id', 'destination_chat_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'source_chat_id' => $this->sourceChatId,
            'destination_chat_id' => $this->destinationChatId,
        ], $this->extra);
    }
}
