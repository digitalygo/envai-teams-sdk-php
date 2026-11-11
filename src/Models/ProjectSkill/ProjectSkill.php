<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ProjectSkill;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/** Shared project skill. Markdown content is wire-faithful and never trimmed. */
final readonly class ProjectSkill implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $id,
        public string $projectId,
        public ?string $createdByUserId,
        public string $name,
        public ?string $description,
        public string $content,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $context = 'ProjectSkill';

        return new self(
            id: Wire::string($data, 'id', $context),
            projectId: Wire::string($data, 'project_id', $context),
            createdByUserId: Wire::nullableString($data, 'created_by_user_id', $context),
            name: Wire::string($data, 'name', $context),
            description: Wire::nullableString($data, 'description', $context),
            content: Wire::string($data, 'content', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, [
                'id',
                'project_id',
                'created_by_user_id',
                'name',
                'description',
                'content',
                'created_at',
                'updated_at',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'project_id' => $this->projectId,
            'created_by_user_id' => $this->createdByUserId,
            'name' => $this->name,
            'description' => $this->description,
            'content' => $this->content,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $this->extra);
    }
}
