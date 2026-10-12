<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Model;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * LLM entry of the catalog. `owned_by` is the model provider, `created` is
 * the Unix release timestamp, models past sunset are removed from the
 * catalog and deprecated models appear with `deprecated: true` until sunset.
 *
 * `sunset_at` stays an ISO 8601 string (wire-faithful), never parsed into
 * DateTimeImmutable.
 */
final readonly class LlmModelEntry implements ArrayHydratable, ModelListEntry
{
    /**
     * @param  list<string>|null  $supportedParameters
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $object,
        public int $created,
        public string $ownedBy,
        public string $type,
        public bool $deprecated,
        public ?string $sunsetAt,
        public array $extra = [],
        public ?string $displayName = null,
        public ?string $description = null,
        public ?string $canonicalSlug = null,
        public ?string $huggingFaceId = null,
        public ?int $contextLength = null,
        public ?int $maxCompletionTokens = null,
        public ?JsonObject $pricing = null,
        public ?JsonObject $architecture = null,
        public ?array $supportedParameters = null,
        public ?JsonObject $defaultParameters = null,
        public ?JsonObject $reasoning = null,
        public ?string $knowledgeCutoff = null,
        public ?string $upstreamExpirationDate = null,
        public ?JsonObject $aliasTarget = null,
        public ?bool $isModerated = null,
        public ?string $syncedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'LlmModelEntry';

        return new self(
            id: Wire::string($data, 'id', $context),
            object: Wire::string($data, 'object', $context),
            created: Wire::int($data, 'created', $context),
            ownedBy: Wire::string($data, 'owned_by', $context),
            type: Wire::string($data, 'type', $context),
            deprecated: Wire::bool($data, 'deprecated', $context),
            sunsetAt: Wire::nullableString($data, 'sunset_at', $context),
            extra: Wire::extra($data, ['id', 'object', 'created', 'owned_by', 'type', 'deprecated', 'sunset_at', 'display_name', 'description', 'canonical_slug', 'hugging_face_id', 'context_length', 'max_completion_tokens', 'pricing', 'architecture', 'supported_parameters', 'default_parameters', 'reasoning', 'knowledge_cutoff', 'upstream_expiration_date', 'alias_target', 'is_moderated', 'synced_at']),
            displayName: Wire::nullableString($data, 'display_name', $context),
            description: Wire::nullableString($data, 'description', $context),
            canonicalSlug: Wire::nullableString($data, 'canonical_slug', $context),
            huggingFaceId: Wire::nullableString($data, 'hugging_face_id', $context),
            contextLength: Wire::nullableInt($data, 'context_length', $context),
            maxCompletionTokens: Wire::nullableInt($data, 'max_completion_tokens', $context),
            pricing: Wire::nullableJsonObject($data, 'pricing', $context),
            architecture: Wire::nullableJsonObject($data, 'architecture', $context),
            supportedParameters: isset($data['supported_parameters']) ? Wire::stringList($data, 'supported_parameters', $context) : null,
            defaultParameters: Wire::nullableJsonObject($data, 'default_parameters', $context),
            reasoning: Wire::nullableJsonObject($data, 'reasoning', $context),
            knowledgeCutoff: Wire::nullableString($data, 'knowledge_cutoff', $context),
            upstreamExpirationDate: Wire::nullableString($data, 'upstream_expiration_date', $context),
            aliasTarget: Wire::nullableJsonObject($data, 'alias_target', $context),
            isModerated: isset($data['is_moderated']) ? Wire::bool($data, 'is_moderated', $context) : null,
            syncedAt: Wire::nullableString($data, 'synced_at', $context),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function object(): string
    {
        return $this->object;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function ownedBy(): string
    {
        return $this->ownedBy;
    }

    public function deprecated(): bool
    {
        return $this->deprecated;
    }

    public function sunsetAt(): ?string
    {
        return $this->sunsetAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'object' => $this->object,
            'created' => $this->created,
            'owned_by' => $this->ownedBy,
            'type' => $this->type,
            'deprecated' => $this->deprecated,
            'sunset_at' => $this->sunsetAt,
        ];

        if ($this->displayName !== null) {
            $payload['display_name'] = $this->displayName;
        }

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        if ($this->canonicalSlug !== null) {
            $payload['canonical_slug'] = $this->canonicalSlug;
        }

        if ($this->huggingFaceId !== null) {
            $payload['hugging_face_id'] = $this->huggingFaceId;
        }

        if ($this->contextLength !== null) {
            $payload['context_length'] = $this->contextLength;
        }

        if ($this->maxCompletionTokens !== null) {
            $payload['max_completion_tokens'] = $this->maxCompletionTokens;
        }

        if ($this->pricing !== null) {
            $payload['pricing'] = $this->pricing->toArray();
        }

        if ($this->architecture !== null) {
            $payload['architecture'] = $this->architecture->toArray();
        }

        if ($this->supportedParameters !== null) {
            $payload['supported_parameters'] = $this->supportedParameters;
        }

        if ($this->defaultParameters !== null) {
            $payload['default_parameters'] = $this->defaultParameters->toArray();
        }

        if ($this->reasoning !== null) {
            $payload['reasoning'] = $this->reasoning->toArray();
        }

        if ($this->knowledgeCutoff !== null) {
            $payload['knowledge_cutoff'] = $this->knowledgeCutoff;
        }

        if ($this->upstreamExpirationDate !== null) {
            $payload['upstream_expiration_date'] = $this->upstreamExpirationDate;
        }

        if ($this->aliasTarget !== null) {
            $payload['alias_target'] = $this->aliasTarget->toArray();
        }

        if ($this->isModerated !== null) {
            $payload['is_moderated'] = $this->isModerated;
        }

        if ($this->syncedAt !== null) {
            $payload['synced_at'] = $this->syncedAt;
        }

        return array_merge($payload, $this->extra);
    }
}
