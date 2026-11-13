<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\OptionalField;

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
        public mixed $model = null,
    ) {
        $value = $model instanceof OptionalField ? ($model->isPresent() ? $model->value() : null) : $model;
        if (($model instanceof OptionalField && $model->isPresent()) || $value !== null) {
            if (! is_string($value) || $value === '' || trim($value) !== $value || preg_match('/(^\s|\s$)/u', $value) === 1) {
                throw new \InvalidArgumentException('Model must be a nonempty unpadded string.');
            }
        }
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
        $model = $this->model instanceof OptionalField ? $this->model->orNull() : $this->model;

        return ['message' => $this->message] + ($this->strictModel === null ? [] : ['strict_model' => $this->strictModel]) + ($this->attachmentIds === [] ? [] : ['attachment_ids' => $this->attachmentIds]) + ($model === null ? [] : ['model' => $model]);
    }
}
