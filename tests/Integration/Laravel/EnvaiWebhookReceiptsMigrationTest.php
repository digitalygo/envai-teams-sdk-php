<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\WebhookIdempotency\LaravelDatabaseWebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\WebhookClaimStatus;
use Digitalygo\Envai\Webhooks\WebhookIdempotencyKey;
use Digitalygo\Envai\Webhooks\WebhookRecordStatus;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

final class EnvaiWebhookReceiptsMigrationTest extends IntegrationTestCase
{
    private const TABLE = 'envai_webhook_receipts';

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

        (new DatabaseServiceProvider($this->app))->register();

        Schema::clearResolvedInstances();
        Schema::setFacadeApplication($this->app);
    }

    public function test_up_creates_the_webhook_receipts_table_with_the_store_columns(): void
    {
        $this->migration()->up();

        $schema = $this->schema();

        $this->assertTrue($schema->hasTable(self::TABLE));
        $this->assertSame([
            'deduplication_key',
            'payload_hash',
            'event_id',
            'event_type',
            'request_id',
            'status',
            'received_at',
            'updated_at',
        ], $schema->getColumnListing(self::TABLE));
    }

    public function test_the_real_store_claims_rows_using_the_published_schema(): void
    {
        $this->migration()->up();

        $store = $this->store();
        $freshKey = $this->key('evt_fresh', 'fresh-payload');
        $freshResult = $store->claim($freshKey);

        $this->assertSame(WebhookClaimStatus::Claimed, $freshResult->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $freshResult->record?->status);
        $this->assertSame($freshKey, $freshResult->record?->key);

        $dispatchedKey = $this->key('evt_dispatched', 'dispatched-payload');
        $store->claim($dispatchedKey);
        $store->markDispatched($dispatchedKey);
        $duplicateResult = $store->claim($dispatchedKey);

        $this->assertSame(WebhookClaimStatus::Duplicate, $duplicateResult->status);
        $this->assertSame(WebhookRecordStatus::Dispatched, $duplicateResult->record?->status);

        $collisionKey = $this->key('evt_collision', 'original-payload');
        $store->claim($collisionKey);
        $collisionResult = $store->claim($this->key('evt_collision', 'different-payload'));

        $this->assertSame(WebhookClaimStatus::Collision, $collisionResult->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $collisionResult->record?->status);
        $this->assertSame($collisionKey->payloadHash, $collisionResult->record?->key->payloadHash);

        $failedKey = $this->key('evt_failed', 'failed-payload');
        $store->claim($failedKey);
        $store->markFailed($failedKey);
        $reclaimedResult = $store->claim($failedKey);

        $this->assertSame(WebhookClaimStatus::Claimed, $reclaimedResult->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $reclaimedResult->record?->status);
        $this->assertSame(
            WebhookRecordStatus::Claimed->value,
            $this->connection()->table(self::TABLE)
                ->where('deduplication_key', $this->deduplicationKey($failedKey))
                ->value('status'),
        );

        $this->assertSame(4, $this->connection()->table(self::TABLE)->count());
    }

    public function test_deduplication_key_is_unique(): void
    {
        $this->migration()->up();

        $key = $this->key('evt_unique', 'unique-payload');
        $row = $this->receiptRow($key);
        $connection = $this->connection();

        $connection->table(self::TABLE)->insert($row);

        $this->expectException(QueryException::class);
        $connection->table(self::TABLE)->insert($row);
    }

    public function test_down_removes_the_webhook_receipts_table(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertFalse($this->schema()->hasTable(self::TABLE));
    }

    public function test_up_does_not_create_sensitive_webhook_columns(): void
    {
        $this->migration()->up();

        $columns = $this->schema()->getColumnListing(self::TABLE);

        foreach (['payload', 'raw_body', 'headers', 'signature', 'token'] as $sensitiveColumn) {
            $this->assertNotContains($sensitiveColumn, $columns);
        }
    }

    private function store(): LaravelDatabaseWebhookIdempotencyStore
    {
        return new LaravelDatabaseWebhookIdempotencyStore($this->connection());
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->app->make('db')->connection('default');

        if (! $connection instanceof ConnectionInterface) {
            $this->fail('The database connection was not initialized.');
        }

        return $connection;
    }

    private function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    private function key(string $eventId, string $payload): WebhookIdempotencyKey
    {
        return new WebhookIdempotencyKey(
            scope: 'account-1',
            eventId: $eventId,
            payloadHash: hash('sha256', $payload),
            eventType: 'created',
            requestId: 'request-1',
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function receiptRow(WebhookIdempotencyKey $key, WebhookRecordStatus $status = WebhookRecordStatus::Claimed): array
    {
        return [
            'deduplication_key' => $this->deduplicationKey($key),
            'payload_hash' => $key->payloadHash,
            'event_id' => $key->eventId,
            'event_type' => $key->eventType,
            'request_id' => $key->requestId,
            'status' => $status->value,
            'received_at' => '2026-08-29T10:00:00.000000+00:00',
            'updated_at' => '2026-08-29T10:00:00.000000+00:00',
        ];
    }

    private function deduplicationKey(WebhookIdempotencyKey $key): string
    {
        return hash('sha256', strlen($key->scope).':'.$key->scope.strlen($key->eventId).':'.$key->eventId);
    }

    /**
     * @return object{up(): void, down(): void}
     */
    private function migration(): object
    {
        return require __DIR__.'/../../../database/migrations/2026_08_29_000001_create_envai_webhook_receipts_table.php';
    }
}
