<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

final readonly class ChatUpdateRequest
{
    public function __construct(public ?string $title) {}

    /** @return array{title: ?string} */
    public function toArray(): array
    {
        return ['title' => $this->title];
    }
}
