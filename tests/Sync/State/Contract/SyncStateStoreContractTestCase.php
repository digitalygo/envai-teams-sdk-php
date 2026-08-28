<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Contract;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

/**
 * Contract test suite for SyncStateStore implementations (project
 * document section 29.5). Every concrete store driver must run this
 * suite unchanged: the file store, PDO SQLite, PDO PostgreSQL (CI) and
 * PDO MySQL (CI) concrete classes extend this case.
 *
 * Concrete classes only implement makeStore() over a fresh, isolated
 * storage fixture (a temporary directory for the file store, a dedicated
 * database or table prefix for the database store). Each test method
 * starts from clean storage, and calling makeStore() again simulates
 * store re-instantiation over the same storage (durability).
 */
abstract class SyncStateStoreContractTestCase extends SyncTestCase
{
    /**
     * Builds a fresh store instance over the shared fixture storage.
     * Calling it again must observe the data written through earlier
     * instances (durability).
     */
    abstract protected function makeStore(float $lockTimeoutSeconds = 5.0): SyncStateStore;

    public function test_fresh_store_has_no_state(): void
    {
        $store = $this->makeStore();

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'query_db'));
        $this->assertNull($store->readyManifest('production'));
        $this->assertCount(0, iterator_to_array($store->resources('production'), false));
    }

    public function test_save_and_read_roundtrip_for_tool(): void
    {
        $store = $this->makeStore();
        $state = $this->sampleState('production', ResourceType::Tool, 'query_db');

        $store->saveResource($state);

        $this->assertStateEquals($state, $store->getResource('production', ResourceType::Tool, 'query_db'));
        $this->assertNull($store->getResource('production', ResourceType::Tool, 'other'));
        $this->assertNull($store->getResource('staging', ResourceType::Tool, 'query_db'));
    }

    public function test_save_and_read_roundtrip_for_agent(): void
    {
        $store = $this->makeStore();
        $state = $this->sampleState('production', ResourceType::Agent, 'orchestrator');

        $store->saveResource($state);

        $this->assertStateEquals($state, $store->getResource('production', ResourceType::Agent, 'orchestrator'));
    }

    public function test_save_and_read_roundtrip_for_team(): void
    {
        $store = $this->makeStore();
        $state = $this->sampleState('production', ResourceType::Team, 'ops');

        $store->saveResource($state);

        $this->assertStateEquals($state, $store->getResource('production', ResourceType::Team, 'ops'));
    }

    public function test_save_replaces_previous_state(): void
    {
        $store = $this->makeStore();

        $store->saveResource(new ManagedResourceState('production', ResourceType::Agent, 'orchestrator', remoteId: 'agt_1'));
        $store->saveResource(new ManagedResourceState('production', ResourceType::Agent, 'orchestrator', remoteId: 'agt_2'));

        $this->assertSame('agt_2', $store->getResource('production', ResourceType::Agent, 'orchestrator')?->remoteId);
        $this->assertCount(1, iterator_to_array($store->resources('production'), false));
    }

    public function test_resources_yields_all_types_for_the_connection(): void
    {
        $store = $this->makeStore();

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a'));
        $store->saveResource(new ManagedResourceState('production', ResourceType::Agent, 'b'));
        $store->saveResource(new ManagedResourceState('production', ResourceType::Team, 'd'));
        $store->saveResource(new ManagedResourceState('other', ResourceType::Tool, 'z'));

        $keys = [];

        foreach ($store->resources('production') as $state) {
            $keys[] = $state->type->value.':'.$state->localKey;
        }

        sort($keys);

        $this->assertSame(['agent:b', 'team:d', 'tool:a'], $keys);
    }

    public function test_resources_yields_nothing_for_unknown_connection(): void
    {
        $store = $this->makeStore();

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a'));

        $this->assertCount(0, iterator_to_array($store->resources('unknown'), false));
    }

    public function test_delete_resource_removes_it(): void
    {
        $store = $this->makeStore();

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a'));
        $store->deleteResource('production', ResourceType::Tool, 'a');

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'a'));
        $this->assertCount(0, iterator_to_array($store->resources('production'), false));
    }

    public function test_delete_unknown_resource_is_a_noop(): void
    {
        $store = $this->makeStore();

        $store->deleteResource('production', ResourceType::Tool, 'nope');

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'nope'));
    }

    public function test_get_resource_returns_null_for_unknown_resource(): void
    {
        $store = $this->makeStore();

        $this->assertNull($store->getResource('production', ResourceType::Agent, 'never_saved'));
    }

    public function test_desired_hash_and_optional_fields_roundtrip(): void
    {
        $store = $this->makeStore();
        $state = new ManagedResourceState('production', ResourceType::Tool, 'minimal', desiredHash: 'sha256:only_hash');

        $store->saveResource($state);

        $read = $store->getResource('production', ResourceType::Tool, 'minimal');

        $this->assertNotNull($read);
        $this->assertSame('sha256:only_hash', $read->desiredHash);
        $this->assertNull($read->remoteId);
        $this->assertNull($read->remoteName);
        $this->assertNull($read->lastAppliedPayload);
        $this->assertNull($read->remoteMarker);
        $this->assertNull($read->synchronizedAt);
        $this->assertSame(ManagedResourceStatus::Missing, $read->status);
    }

    public function test_remote_marker_roundtrip(): void
    {
        $store = $this->makeStore();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteMarker: '2026-08-28T10:00:00Z'));

        $this->assertSame('2026-08-28T10:00:00Z', $store->getResource('production', ResourceType::Tool, 'a')?->remoteMarker);
    }

    public function test_synchronized_at_is_preserved(): void
    {
        $store = $this->makeStore();
        $synchronizedAt = new \DateTimeImmutable('2026-08-28T10:00:00.123456+02:00');
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', synchronizedAt: $synchronizedAt));

        $this->assertSame(
            '2026-08-28T10:00:00.123456+02:00',
            $store->getResource('production', ResourceType::Tool, 'a')?->synchronizedAt?->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function test_empty_object_payload_is_preserved(): void
    {
        $store = $this->makeStore();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'obj', lastAppliedPayload: new JsonObject([])));

        $payload = $store->getResource('production', ResourceType::Tool, 'obj')?->lastAppliedPayload;

        $this->assertInstanceOf(JsonObject::class, $payload);
        $this->assertTrue($payload->isEmpty());
    }

    public function test_empty_list_payload_is_preserved(): void
    {
        $store = $this->makeStore();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'list', lastAppliedPayload: new JsonArray([])));

        $payload = $store->getResource('production', ResourceType::Tool, 'list')?->lastAppliedPayload;

        $this->assertInstanceOf(JsonArray::class, $payload);
        $this->assertTrue($payload->isEmpty());
    }

    public function test_null_payload_is_preserved(): void
    {
        $store = $this->makeStore();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'null', lastAppliedPayload: null));

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'null')?->lastAppliedPayload);
    }

    public function test_nested_object_and_list_payload_roundtrip(): void
    {
        $store = $this->makeStore();
        $payload = new JsonObject([
            'name' => 'query_db',
            'config' => new JsonObject([]),
            'tags' => new JsonArray(['a', 'b']),
            'nullable' => null,
        ]);
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'nested', lastAppliedPayload: $payload));

        $read = $store->getResource('production', ResourceType::Tool, 'nested')?->lastAppliedPayload;

        $this->assertInstanceOf(JsonObject::class, $read);
        $this->assertInstanceOf(JsonObject::class, $read->get('config'));
        $this->assertTrue($read->get('config')->isEmpty());
        $this->assertInstanceOf(JsonArray::class, $read->get('tags'));
        $this->assertSame(['a', 'b'], $read->get('tags')->toArray());
        $this->assertNull($read->get('nullable'));
    }

    public function test_manifest_ready_roundtrip(): void
    {
        $store = $this->makeStore();

        $this->assertNull($store->readyManifest('production'));

        $store->markManifestReady('production', 'sha256:1');

        $ready = $store->readyManifest('production');

        $this->assertInstanceOf(ReadyManifest::class, $ready);
        $this->assertSame('production', $ready->connection);
        $this->assertSame('sha256:1', $ready->manifestHash);
        $this->assertInstanceOf(\DateTimeImmutable::class, $ready->synchronizedAt);

        $store->markManifestReady('production', 'sha256:2');

        $this->assertSame('sha256:2', $store->readyManifest('production')?->manifestHash);
    }

    public function test_ready_manifest_is_per_connection(): void
    {
        $store = $this->makeStore();

        $store->markManifestReady('production', 'sha256:1');

        $this->assertNull($store->readyManifest('staging'));
    }

    public function test_acquire_lock_and_release(): void
    {
        $store = $this->makeStore();

        $lock = $store->acquireLock('production');

        $this->assertInstanceOf(SyncLock::class, $lock);
        $this->assertSame('production', $lock->connection());
        $this->assertFalse($lock->isReleased());

        $lock->release();

        $this->assertTrue($lock->isReleased());
    }

    public function test_acquiring_a_held_lock_times_out(): void
    {
        $first = $this->makeStore(0.3);
        $second = $this->makeStore(0.3);

        $lock = $first->acquireLock('production');
        $this->assertFalse($lock->isReleased());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not acquire envai state lock');

        $second->acquireLock('production');
    }

    public function test_release_is_idempotent_and_frees_the_connection(): void
    {
        $first = $this->makeStore();

        $lock = $first->acquireLock('production');
        $lock->release();
        $lock->release();

        $this->assertTrue($lock->isReleased());

        $second = $this->makeStore();
        $second->acquireLock('production')->release();

        $this->addToAssertionCount(1);
    }

    public function test_state_persists_across_store_reinstantiation(): void
    {
        $first = $this->makeStore();
        $state = $this->sampleState('production', ResourceType::Tool, 'query_db');
        $first->saveResource($state);
        $first->markManifestReady('production', 'sha256:persisted');

        $second = $this->makeStore();

        $this->assertStateEquals($state, $second->getResource('production', ResourceType::Tool, 'query_db'));
        $this->assertSame('sha256:persisted', $second->readyManifest('production')?->manifestHash);
    }

    public function test_withers_reflect_saved_state(): void
    {
        $store = $this->makeStore();

        $original = new ManagedResourceState('production', ResourceType::Tool, 'calc', status: ManagedResourceStatus::Missing);
        $store->saveResource($original);

        $updated = $original
            ->withStatus(ManagedResourceStatus::Applied)
            ->withRemoteId('twl_calc')
            ->withRemoteName('calc')
            ->withDesiredHash('sha256:desired')
            ->withLastAppliedPayload(new JsonObject(['name' => 'calc']))
            ->withRemoteMarker('marker-1')
            ->withSynchronizedAt(new \DateTimeImmutable('2026-08-28T10:00:00+00:00'));

        $store->saveResource($updated);

        $read = $store->getResource('production', ResourceType::Tool, 'calc');

        $this->assertSame(ManagedResourceStatus::Applied, $read?->status);
        $this->assertSame('twl_calc', $read?->remoteId);
        $this->assertSame('sha256:desired', $read?->desiredHash);
        $this->assertSame('marker-1', $read?->remoteMarker);
        $this->assertInstanceOf(JsonObject::class, $read?->lastAppliedPayload);
    }

    protected function sampleState(string $connection, ResourceType $type, string $localKey): ManagedResourceState
    {
        return new ManagedResourceState(
            connection: $connection,
            type: $type,
            localKey: $localKey,
            remoteId: 'remote_'.$localKey,
            remoteName: $localKey,
            desiredHash: 'sha256:'.str_repeat('a', 64),
            lastAppliedPayload: new JsonObject(['name' => $localKey]),
            remoteMarker: '2026-08-28T10:00:00Z',
            synchronizedAt: new \DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            status: ManagedResourceStatus::Applied,
        );
    }

    protected function assertStateEquals(ManagedResourceState $expected, ?ManagedResourceState $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame($expected->connection, $actual->connection);
        $this->assertSame($expected->type, $actual->type);
        $this->assertSame($expected->localKey, $actual->localKey);
        $this->assertSame($expected->remoteId, $actual->remoteId);
        $this->assertSame($expected->remoteName, $actual->remoteName);
        $this->assertSame($expected->desiredHash, $actual->desiredHash);
        $this->assertSame($expected->status, $actual->status);
        $this->assertSame(
            $expected->synchronizedAt?->format('Y-m-d\TH:i:s.uP'),
            $actual->synchronizedAt?->format('Y-m-d\TH:i:s.uP'),
        );
        $this->assertSame($expected->remoteMarker, $actual->remoteMarker);
        $this->assertEquals($expected->lastAppliedPayload, $actual->lastAppliedPayload);
    }
}
