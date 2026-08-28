<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Model;

/**
 * One entry of the model catalog, either an LLM (`type: llm`) or a team
 * alias (`type: team`).
 *
 * Common accessors cover the shared surface; use instanceof to reach the
 * entry-specific fields (`created` on LlmModelEntry, `team_id` on
 * TeamModelEntry).
 */
interface ModelListEntry
{
    public function id(): string;

    public function object(): string;

    public function type(): string;

    public function ownedBy(): string;

    public function deprecated(): bool;

    public function sunsetAt(): ?string;
}
