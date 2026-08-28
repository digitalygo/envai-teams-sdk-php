<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Client;

use Digitalygo\Envai\Sync\Client\StateStoreFactory;
use Digitalygo\Envai\Sync\State\File\FileSyncStateStore;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use PHPUnit\Framework\TestCase;

final class StateStoreFactoryTest extends TestCase
{
    public function test_inmemory_driver_builds_the_in_memory_store(): void
    {
        $store = (new StateStoreFactory)->create('inmemory');

        $this->assertInstanceOf(InMemorySyncStateStore::class, $store);
        $this->assertInstanceOf(SyncStateStore::class, $store);
    }

    public function test_file_driver_builds_the_file_store(): void
    {
        if (! class_exists(FileSyncStateStore::class)) {
            $this->markTestSkipped('FileSyncStateStore is delivered by a parallel package and is not present yet.');
        }

        $store = (new StateStoreFactory)->create('file', ['path' => sys_get_temp_dir().'/envai-state.json']);

        $this->assertInstanceOf(FileSyncStateStore::class, $store);
        $this->assertInstanceOf(SyncStateStore::class, $store);
    }

    public function test_pdo_driver_builds_the_database_store(): void
    {
        if (! class_exists(PdoSyncStateStore::class)) {
            $this->markTestSkipped('PdoSyncStateStore is delivered by a parallel package and is not present yet.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $store = (new StateStoreFactory)->create('pdo', ['pdo' => $pdo, 'table_prefix' => 'my_sync_']);

        $this->assertInstanceOf(PdoSyncStateStore::class, $store);
        $this->assertInstanceOf(SyncStateStore::class, $store);
    }

    public function test_pdo_driver_requires_a_pdo_instance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pdo');

        (new StateStoreFactory)->create('pdo', ['pdo' => 'not-a-pdo']);
    }

    public function test_unknown_driver_lists_supported_drivers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inmemory, file, pdo');

        (new StateStoreFactory)->create('redis');
    }
}
