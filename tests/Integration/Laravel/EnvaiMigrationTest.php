<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the published migration up()/down() against a real SQLite
 * connection through the Schema facade: the resources and manifests
 * tables are created with the documented columns and unique keys and
 * dropped again. Skipped cleanly when the Illuminate packages are
 * absent.
 */
final class EnvaiMigrationTest extends IntegrationTestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container;
        $this->app->instance('config', new ConfigRepository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));

        // The database service provider binds `db`, `db.schema` and
        // `db.connection`, exactly like a real application.
        (new DatabaseServiceProvider($this->app))->register();

        Schema::clearResolvedInstances();
        Schema::setFacadeApplication($this->app);
    }

    public function test_up_creates_the_resources_and_manifests_tables(): void
    {
        $migration = $this->migration();

        $migration->up();

        $schema = $this->app->make('db')->connection('default')->getSchemaBuilder();

        $this->assertTrue($schema->hasTable('envai_sync_resources'));
        $this->assertTrue($schema->hasTable('envai_sync_manifests'));
        $this->assertFalse($schema->hasTable('envai_sync_locks'));

        $this->assertTrue($schema->hasColumns('envai_sync_resources', [
            'connection', 'resource_type', 'local_key', 'remote_id', 'remote_name',
            'desired_hash', 'last_applied_payload', 'remote_marker', 'status',
            'synchronized_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue($schema->hasColumns('envai_sync_manifests', [
            'connection', 'manifest_hash', 'synchronized_at', 'metadata',
        ]));
    }

    public function test_the_resources_table_stores_and_reads_a_row(): void
    {
        $this->migration()->up();

        $connection = $this->app->make('db')->connection('default');

        $connection->table('envai_sync_resources')->insert([
            'connection' => 'production',
            'resource_type' => 'tool',
            'local_key' => 'query_db',
            'status' => 'missing',
            'created_at' => '2026-08-28T10:00:00+00:00',
            'updated_at' => '2026-08-28T10:00:00+00:00',
        ]);

        $this->assertSame(
            'query_db',
            $connection->table('envai_sync_resources')->where('local_key', 'query_db')->value('local_key'),
        );
    }

    public function test_the_unique_key_is_connection_plus_type_plus_local_key(): void
    {
        $migration = $this->migration();
        $migration->up();

        $connection = $this->app->make('db')->connection('default');
        $connection->table('envai_sync_resources')->insert([
            'connection' => 'production',
            'resource_type' => 'tool',
            'local_key' => 'query_db',
            'status' => 'missing',
            'created_at' => '2026-08-28T10:00:00+00:00',
            'updated_at' => '2026-08-28T10:00:00+00:00',
        ]);

        $this->expectException(QueryException::class);

        $connection->table('envai_sync_resources')->insert([
            'connection' => 'production',
            'resource_type' => 'tool',
            'local_key' => 'query_db',
            'status' => 'missing',
            'created_at' => '2026-08-28T10:00:00+00:00',
            'updated_at' => '2026-08-28T10:00:00+00:00',
        ]);
    }

    public function test_down_drops_both_tables(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $schema = $this->app->make('db')->connection('default')->getSchemaBuilder();

        $this->assertFalse($schema->hasTable('envai_sync_resources'));
        $this->assertFalse($schema->hasTable('envai_sync_manifests'));
    }

    public function test_down_is_idempotent(): void
    {
        $migration = $this->migration();

        $migration->down();

        $this->addToAssertionCount(1);
    }

    /**
     * @return object{up(): void, down(): void}
     */
    private function migration(): object
    {
        return require __DIR__.'/../../../database/migrations/2026_08_28_000000_create_envai_sync_tables.php';
    }
}
