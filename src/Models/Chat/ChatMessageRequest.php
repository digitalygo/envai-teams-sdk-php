<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to send a user message to a chat. The message opens a turn and
 * becomes the turn input.
 */
final readonly class ChatMessageRequest
{
    /** @param array<mixed> $attachmentIds */
    public function __construct(
        public string $message,
        public array $attachmentIds = [],
        public ?bool $strictModel = null,
    ) {
        if (! array_is_list($attachmentIds)) {
            throw new \InvalidArgumentException('Attachment IDs must be a list.');
        }
        $seen = [];
        foreach ($attachmentIds as $id) {
            if (! is_string($id) || $id === '' || isset($seen[$id])) {
                throw new \InvalidArgumentException('Attachment IDs must be distinct nonempty strings.');
            }
            $seen[$id] = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['message' => $this->message] + ($this->strictModel === null ? [] : ['strict_model' => $this->strictModel]) + ($this->attachmentIds === [] ? [] : ['attachment_ids' => $this->attachmentIds]);
    }
}
