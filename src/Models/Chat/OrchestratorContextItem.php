<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * One element of the `orchestrator_context` supplied on restore. The first
 * element must have kind `compacted`; subsequent elements are `assistant`
 * orchestrator messages emitted after the compact point.
 *
 * Timestamps stay ISO 8601 strings (wire-faithful). Unknown keys are
 * preserved into $extra so a future context item field survives hydration.
 */
final readonly class OrchestratorContextItem implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public OrchestratorContextKind $kind,
        public string $content,
        public ?string $turnId = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'OrchestratorContextItem';

        return new self(
            kind: Wire::enum($data, 'kind', OrchestratorContextKind::class, $context),
            content: Wire::string($data, 'content', $context),
            turnId: Wire::nullableString($data, 'turn_id', $context),
            extra: Wire::extra($data, ['kind', 'content', 'turn_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'kind' => $this->kind->value,
            'content' => $this->content,
        ];

        if ($this->turnId !== null) {
            $payload['turn_id'] = $this->turnId;
        }

        return array_merge($payload, $this->extra);
    }
}
