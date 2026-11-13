<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ProjectSkill;

use Digitalygo\Envai\Models\Common\OptionalField;

final readonly class ProjectSkillUpdateRequest
{
    public OptionalField $name;

    public OptionalField $description;

    public OptionalField $content;

    public function __construct(
        ?OptionalField $name = null,
        ?OptionalField $description = null,
        ?OptionalField $content = null,
    ) {
        $this->name = $name ?? OptionalField::absent();
        $this->description = $description ?? OptionalField::absent();
        $this->content = $content ?? OptionalField::absent();
    }

    public static function name(string $name): self
    {
        return new self(name: OptionalField::of($name));
    }

    public static function content(string $content): self
    {
        return new self(content: OptionalField::of($content));
    }

    public static function description(?string $description): self
    {
        return new self(description: OptionalField::of($description));
    }

    public static function clearDescription(): self
    {
        return self::description(null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [];

        self::putString($payload, 'name', $this->name);
        self::putNullableString($payload, 'description', $this->description);
        self::putString($payload, 'content', $this->content);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function putString(array &$payload, string $key, OptionalField $field): void
    {
        if ($field->isAbsent()) {
            return;
        }

        $value = $field->value();

        if (! is_string($value)) {
            throw new \LogicException(sprintf('ProjectSkillUpdateRequest field "%s" must be a string.', $key));
        }

        $payload[$key] = $value;
    }

    /** @param array<string, mixed> $payload */
    private static function putNullableString(array &$payload, string $key, OptionalField $field): void
    {
        if ($field->isAbsent()) {
            return;
        }

        $value = $field->value();

        if ($value !== null && ! is_string($value)) {
            throw new \LogicException(sprintf('ProjectSkillUpdateRequest field "%s" must be a string or null.', $key));
        }

        $payload[$key] = $value;
    }
}
