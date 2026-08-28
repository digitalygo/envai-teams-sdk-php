<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Tool;

use Digitalygo\Envai\Models\Common\OptionalField;

/**
 * Payload to update a client tool. Only `description`, `parameters` and
 * `timeout_seconds` are accepted; `name`, `kind`, `workspace_id` and
 * `user_id` are prohibited and never serialized.
 *
 * `description` distinguishes an omitted field from an explicit null (which
 * clears the description), so each field is wrapped in an OptionalField and
 * toArray() emits only present values. Use the static factories, which wrap
 * the value and mark the other fields absent.
 */
final readonly class UserToolUpdateRequest
{
    public function __construct(
        public OptionalField $description,
        public OptionalField $parameters,
        public OptionalField $timeoutSeconds,
    ) {}

    public static function description(?string $description): self
    {
        return new self(
            description: OptionalField::of($description),
            parameters: OptionalField::absent(),
            timeoutSeconds: OptionalField::absent(),
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function parameters(array $parameters): self
    {
        return new self(
            description: OptionalField::absent(),
            parameters: OptionalField::of($parameters),
            timeoutSeconds: OptionalField::absent(),
        );
    }

    public static function timeoutSeconds(int $timeoutSeconds): self
    {
        return new self(
            description: OptionalField::absent(),
            parameters: OptionalField::absent(),
            timeoutSeconds: OptionalField::of($timeoutSeconds),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->description->isPresent()) {
            $payload['description'] = $this->description->value();
        }

        if ($this->parameters->isPresent()) {
            $payload['parameters'] = $this->parameters->value();
        }

        if ($this->timeoutSeconds->isPresent()) {
            $payload['timeout_seconds'] = $this->timeoutSeconds->value();
        }

        return $payload;
    }
}
