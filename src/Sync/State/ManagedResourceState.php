<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * Immutable record of one managed resource as stored per connection.
 *
 * The identity of a resource is connection + type + local key. The local
 * key is the stable local identity (markdown file name for agents, file
 * name for teams, registry key for tools); the remote id and remote name
 * are the identities observed on the Envai side. Agents and teams are
 * addressed by remote id after the first link, tools by remote name
 * (tool names are not updatable).
 *
 * lastAppliedPayload is the normalized remote-shaped payload of the last
 * successful mutation, or the remote payload captured at adoption time.
 * desiredHash is the canonical sha256 of the desired definition payload
 * (see CanonicalManifestHasher::resourceHash); it is what the manifest
 * hash covers per resource, so a planner can detect that the applied
 * state no longer matches the desired definition.
 *
 * All mutators are withers returning a new instance; the original is
 * never modified.
 */
final readonly class ManagedResourceState
{
    /**
     * @param  mixed  $lastAppliedPayload  Normalized array (or JsonObject/JsonArray wrapper) of the last applied remote payload; null when never applied.
     * @param  mixed  $remoteMarker  Server-side marker observed with the last mutation, for example `updated_at`.
     * @param  array<string, mixed>  $metadata  Store-specific metadata preserved as-is.
     */
    public function __construct(
        public string $connection,
        public ResourceType $type,
        public string $localKey,
        public ?string $remoteId = null,
        public ?string $remoteName = null,
        public ?string $desiredHash = null,
        public mixed $lastAppliedPayload = null,
        public mixed $remoteMarker = null,
        public ?\DateTimeImmutable $synchronizedAt = null,
        public ManagedResourceStatus $status = ManagedResourceStatus::Missing,
        public array $metadata = [],
    ) {}

    public function withStatus(ManagedResourceStatus $status): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $this->remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $status,
            metadata: $this->metadata,
        );
    }

    public function withRemoteId(?string $remoteId): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $remoteId,
            remoteName: $this->remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $this->status,
            metadata: $this->metadata,
        );
    }

    public function withRemoteName(?string $remoteName): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $this->status,
            metadata: $this->metadata,
        );
    }

    public function withDesiredHash(?string $desiredHash): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $this->remoteName,
            desiredHash: $desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $this->status,
            metadata: $this->metadata,
        );
    }

    public function withLastAppliedPayload(mixed $lastAppliedPayload): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $this->remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $this->status,
            metadata: $this->metadata,
        );
    }

    public function withRemoteMarker(mixed $remoteMarker): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $this->remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $this->status,
            metadata: $this->metadata,
        );
    }

    /**
     * Alias of withRemoteMarker().
     */
    public function withMarker(mixed $remoteMarker): self
    {
        return $this->withRemoteMarker($remoteMarker);
    }

    public function withSynchronizedAt(?\DateTimeImmutable $synchronizedAt): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $this->remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $synchronizedAt,
            status: $this->status,
            metadata: $this->metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            connection: $this->connection,
            type: $this->type,
            localKey: $this->localKey,
            remoteId: $this->remoteId,
            remoteName: $this->remoteName,
            desiredHash: $this->desiredHash,
            lastAppliedPayload: $this->lastAppliedPayload,
            remoteMarker: $this->remoteMarker,
            synchronizedAt: $this->synchronizedAt,
            status: $this->status,
            metadata: $metadata,
        );
    }
}
