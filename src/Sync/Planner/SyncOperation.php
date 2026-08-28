<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

use Digitalygo\Envai\Sync\Diff\DefinitionDiff;
use Digitalygo\Envai\Sync\State\ResourceType;

/**
 * One planned operation for a single resource. The id is the stable
 * `<type>:<localKey>` identity, for example `tool:query_db` or
 * `agent:researcher`.
 *
 * blocking marks operations the policy did not authorize: they carry
 * action None and a reasonCode (drift, conflict, adoption_required,
 * missing_remote) and the reconciler skips them. Non-blocking skipped
 * operations (out_of_scope, prune_disabled) carry action None with a
 * reason but never block a plan.
 *
 * The payloads are normalized (see PayloadNormalizer): desiredPayload is
 * the canonical desired shape covered by the manifest hash, team payloads
 * reference local agent keys that the reconciler rewrites to remote ids
 * at apply time. destructive is true only for deletions.
 */
final readonly class SyncOperation
{
    /**
     * @param  array<string, mixed>|null  $desiredPayload
     * @param  array<string, mixed>|null  $lastAppliedPayload
     * @param  array<string, mixed>|null  $remotePayload
     */
    public function __construct(
        public string $id,
        public ResourceType $type,
        public string $localKey,
        public SyncClassification $classification,
        public SyncAction $action,
        public bool $blocking,
        public ?string $reasonCode = null,
        public ?string $remoteId = null,
        public ?string $remoteName = null,
        public ?string $remoteMarker = null,
        public ?array $desiredPayload = null,
        public ?array $lastAppliedPayload = null,
        public ?array $remotePayload = null,
        public ?DefinitionDiff $diff = null,
        public bool $destructive = false,
    ) {}

    /**
     * Serializes the operation to the stable machine-readable shape used
     * by the CLI `--json` output: the identity, classification, action
     * and policy flags as strings/booleans, the observed remote
     * identities and a compact `changes` list (path + kind per leaf
     * difference, without the payload values, which may be large and are
     * already covered by the manifest hash).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $changes = [];

        foreach ($this->diff->entries ?? [] as $entry) {
            $changes[] = [
                'path' => $entry->path,
                'kind' => $entry->kind->value,
            ];
        }

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'local_key' => $this->localKey,
            'classification' => $this->classification->value,
            'action' => $this->action->value,
            'blocking' => $this->blocking,
            'reason_code' => $this->reasonCode,
            'remote_id' => $this->remoteId,
            'remote_name' => $this->remoteName,
            'remote_marker' => $this->remoteMarker,
            'destructive' => $this->destructive,
            'changes' => $changes,
        ];
    }
}
