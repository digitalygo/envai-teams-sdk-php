<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State;

use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

final class InMemorySyncStateStoreTest extends SyncTestCase
{
    public function test_save_and_read_roundtrip(): void
    {
        $store = new InMemorySyncStateStore;
        $state = new ManagedResourceState(
            connection: 'production',
            type: ResourceType::Tool,
            localKey: 'query_db',
            remoteId: 'twl_1',
            remoteName: 'query_db',
            desiredHash: 'sha256:abc',
            lastAppliedPayload: ['name' => 'query_db'],
            remoteMarker: '2026-08-28T10:00:00Z',
            synchronizedAt: new \DateTimeImmutable,
            status: ManagedResourceStatus::Applied,
        );

        $store->saveResource($state);

        $this->assertEquals($state, $store->getResource('production', ResourceType::Tool, 'query_db'));
        $this->assertNull($store->getResource('production', ResourceType::Tool, 'other'));
        $this->assertNull($store->getResource('staging', ResourceType::Tool, 'query_db'));
    }

    public function test_save_replaces_previous_state(): void
    {
        $store = new InMemorySyncStateStore;

        $store->saveResource(new ManagedResourceState('c', ResourceType::Agent, 'orchestrator', remoteId: 'agt_1'));
        $store->saveResource(new ManagedResourceState('c', ResourceType::Agent, 'orchestrator', remoteId: 'agt_2'));

        $this->assertSame('agt_2', $store->getResource('c', ResourceType::Agent, 'orchestrator')?->remoteId);
    }

    public function test_resources_yields_all_types_for_the_connection(): void
    {
        $store = new InMemorySyncStateStore;

        $store->saveResource(new ManagedResourceState('c', ResourceType::Tool, 'a'));
        $store->saveResource(new ManagedResourceState('c', ResourceType::Agent, 'b'));
        $store->saveResource(new ManagedResourceState('c', ResourceType::Team, 'd'));
        $store->saveResource(new ManagedResourceState('other', ResourceType::Tool, 'z'));

        $keys = [];

        foreach ($store->resources('c') as $state) {
            $keys[] = $state->type->value.':'.$state->localKey;
        }

        sort($keys);

        $this->assertSame(['agent:b', 'team:d', 'tool:a'], $keys);
    }

    public function test_delete_resource_removes_it(): void
    {
        $store = new InMemorySyncStateStore;

        $store->saveResource(new ManagedResourceState('c', ResourceType::Tool, 'a'));
        $store->deleteResource('c', ResourceType::Tool, 'a');

        $this->assertNull($store->getResource('c', ResourceType::Tool, 'a'));
    }

    public function test_delete_unknown_resource_is_a_noop(): void
    {
        $store = new InMemorySyncStateStore;

        $store->deleteResource('c', ResourceType::Tool, 'nope');

        $this->assertNull($store->getResource('c', ResourceType::Tool, 'nope'));
    }

    public function test_manifest_ready_roundtrip(): void
    {
        $store = new InMemorySyncStateStore;

        $this->assertNull($store->readyManifest('c'));

        $store->markManifestReady('c', 'sha256:1');

        $ready = $store->readyManifest('c');

        $this->assertInstanceOf(ReadyManifest::class, $ready);
        $this->assertSame('c', $ready->connection);
        $this->assertSame('sha256:1', $ready->manifestHash);
        $this->assertInstanceOf(\DateTimeImmutable::class, $ready->synchronizedAt);

        $store->markManifestReady('c', 'sha256:2');

        $this->assertSame('sha256:2', $store->readyManifest('c')?->manifestHash);
    }

    public function test_ready_manifest_is_per_connection(): void
    {
        $store = new InMemorySyncStateStore;

        $store->markManifestReady('c', 'sha256:1');

        $this->assertNull($store->readyManifest('other'));
    }

    public function test_acquire_lock_and_release(): void
    {
        $store = new InMemorySyncStateStore;

        $lock = $store->acquireLock('c');

        $this->assertInstanceOf(SyncLock::class, $lock);
        $this->assertSame('c', $lock->connection());
        $this->assertFalse($lock->isReleased());

        $lock->release();

        $this->assertTrue($lock->isReleased());
    }

    public function test_acquiring_an_held_lock_throws(): void
    {
        $store = new InMemorySyncStateStore;

        $store->acquireLock('c');

        $this->expectException(\RuntimeException::class);

        $store->acquireLock('c');
    }

    public function test_release_is_idempotent_and_frees_the_connection(): void
    {
        $store = new InMemorySyncStateStore;

        $lock = $store->acquireLock('c');
        $lock->release();
        $lock->release();

        $this->assertTrue($lock->isReleased());

        $store->acquireLock('c')->release();

        $this->addToAssertionCount(1);
    }

    public function test_locks_are_per_connection(): void
    {
        $store = new InMemorySyncStateStore;

        $store->acquireLock('c');
        $store->acquireLock('other');

        $this->addToAssertionCount(1);
    }

    public function test_managed_resource_state_withers_return_new_instances(): void
    {
        $state = new ManagedResourceState('c', ResourceType::Tool, 'a', status: ManagedResourceStatus::Missing);

        $updated = $state->withStatus(ManagedResourceStatus::Applied)->withRemoteId('twl_1')->withRemoteName('a')->withDesiredHash('sha256:x')->withRemoteMarker('m')->withSynchronizedAt(new \DateTimeImmutable)->withLastAppliedPayload(['name' => 'a']);

        $this->assertSame(ManagedResourceStatus::Missing, $state->status);
        $this->assertSame(ManagedResourceStatus::Applied, $updated->status);
        $this->assertSame('twl_1', $updated->remoteId);
        $this->assertSame('sha256:x', $updated->desiredHash);
        $this->assertSame(['name' => 'a'], $updated->lastAppliedPayload);
        $this->assertNull($updated->metadata['anything'] ?? null);
    }

    public function test_metadata_is_preserved_by_withers(): void
    {
        $state = new ManagedResourceState('c', ResourceType::Team, 't', metadata: ['origin' => 'file']);

        $this->assertSame('file', $state->withStatus(ManagedResourceStatus::Applied)->metadata['origin']);
    }
}
