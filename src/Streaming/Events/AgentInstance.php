<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * A running agent instance on the event stream, not a definition.
 *
 * A team may run two instances of the same role in parallel, so
 * `instanceId` is unique per running agent, `name` identifies the role and
 * `parentId` links the delegation tree. The root orchestrator has
 * `parentId: null`.
 */
final readonly class AgentInstance
{
    /**
     * @param  array<string, mixed>  $extra  Unknown agent fields preserved for forward compatibility.
     */
    public function __construct(
        public string $instanceId,
        public string $name,
        public string $role,
        public ?string $parentId,
        public ?string $model,
        public array $extra = [],
    ) {}
}
