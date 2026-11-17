<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ProjectSkill;

use Digitalygo\Envai\Models\Common\OptionalField;

/** Project skill creation payload. Only name, description and content are serialized. */
final readonly class ProjectSkillCreateRequest
{
    public string $name;

    public string $content;

    public OptionalField $description;

    public function __construct(string $name, string $content, ?OptionalField $description = null)
    {
        $this->name = $name;
        $this->content = $content;
        $this->description = $description ?? OptionalField::absent();
    }

    public static function withDescription(string $name, string $content, ?string $description): self
    {
        return new self($name, $content, OptionalField::of($description));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'content' => $this->content,
        ];

        if ($this->description->isPresent()) {
            $value = $this->description->value();

            if ($value !== null && ! is_string($value)) {
                throw new \LogicException('ProjectSkillCreateRequest field "description" must be a string or null.');
            }

            $payload['description'] = $value;
        }

        return $payload;
    }
}
