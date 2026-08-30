<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\WebhookIdempotency\LaravelDatabaseWebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\WebhookClaimStatus;
use Digitalygo\Envai\Webhooks\WebhookIdempotencyKey;
use Digitalygo\Envai\Webhooks\WebhookRecordStatus;
use Digitalygo\Envai\Webhooks\WebhookStoreException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;

final class LaravelDatabaseWebhookIdempotencyStoreTest extends IntegrationTestCase
{
    private const TABLE = 'envai_webhook_receipts';

    private ?ConnectionInterface $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = new ConnectionFactory(new Container);
        $connection = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'default');

        if (! $connection instanceof ConnectionInterface) {
            $this->fail('The factory did not return an Illuminate ConnectionInterface.');
        }

        $this->connection = $connection;
        $schema = $connection->getSchemaBuilder();

        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->string('deduplication_key', 64)->primary();
            $table->string('payload_hash', 64);
            $table->string('event_id', 191);
            $table->string('event_type', 191)->nullable();
            $table->string('request_id', 191)->nullable();
            $table->string('status', 32);
            $table->string('received_at', 64);
            $table->string('updated_at', 64);
        });
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $schema = $this->connection->getSchemaBuilder();

            if ($schema->hasTable(self::TABLE)) {
                $schema->drop(self::TABLE);
            }
        }

        $this->connection = null;

        parent::tearDown();
    }

    public function test_claim_initially_inserts_a_claimed_receipt(): void
    {
        $key = $this->key();
        $result = $this->store()->claim($key);
        $row = (array) $this->connection()->table(self::TABLE)->first();

        $this->assertSame(WebhookClaimStatus::Claimed, $result->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $result->record?->status);
        $this->assertSame($key, $result->record?->key);
        $this->assertSame($this->deduplicationKey($key), $row['deduplication_key']);
        $this->assertSame($key->payloadHash, $row['payload_hash']);
        $this->assertSame($key->eventId, $row['event_id']);
        $this->assertSame($key->eventType, $row['event_type']);
        $this->assertSame($key->requestId, $row['request_id']);
        $this->assertSame(WebhookRecordStatus::Claimed->value, $row['status']);
        $this->assertIsString($row['received_at']);
        $this->assertIsString($row['updated_at']);
        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('raw_body', $row);
        $this->assertArrayNotHasKey('headers', $row);
        $this->assertArrayNotHasKey('signature', $row);
        $this->assertSame(1, $this->connection()->table(self::TABLE)->count());
    }

    public function test_deduplication_key_uses_length_prefixed_scope_and_event_id(): void
    {
        $first = new WebhookIdempotencyKey('ab', 'c', 'hash-1');
        $second = new WebhookIdempotencyKey('a', 'bc', 'hash-2');

        $this->assertNotSame($this->deduplicationKey($first), $this->deduplicationKey($second));
        $this->assertSame(64, strlen($this->deduplicationKey($first)));
    }

    public function test_same_hash_after_mark_dispatched_is_a_duplicate(): void
    {
        $key = $this->key();
        $store = $this->store();

        $store->claim($key);
        $store->markDispatched($key);
        $result = $store->claim($key);

        $this->assertSame(WebhookClaimStatus::Duplicate, $result->status);
        $this->assertSame(WebhookRecordStatus::Dispatched, $result->record?->status);
        $this->assertSame(1, $this->connection()->table(self::TABLE)->count());
    }

    public function test_same_hash_while_claimed_is_already_claimed(): void
    {
        $key = $this->key();
        $store = $this->store();

        $store->claim($key);
        $result = $store->claim($key);

        $this->assertSame(WebhookClaimStatus::AlreadyClaimed, $result->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $result->record?->status);
        $this->assertSame(1, $this->connection()->table(self::TABLE)->count());
    }

    public function test_different_hash_for_the_same_scope_and_event_id_is_a_collision(): void
    {
        $store = $this->store();

        $store->claim($this->key('hash-1'));
        $result = $store->claim($this->key('hash-2'));

        $this->assertSame(WebhookClaimStatus::Collision, $result->status);
        $this->assertSame('hash-1', $result->record?->key->payloadHash);
        $this->assertSame(WebhookRecordStatus::Claimed, $result->record?->status);
        $this->assertSame(1, $this->connection()->table(self::TABLE)->count());
    }

    public function test_claim_can_be_marked_dispatched_or_failed(): void
    {
        $store = $this->store();
        $dispatchedKey = $this->key('hash-dispatched');
        $failedKey = $this->key('hash-failed', 'evt_failed');

        $store->claim($dispatchedKey);
        $store->markDispatched($dispatchedKey);
        $this->assertSame(
            WebhookRecordStatus::Dispatched->value,
            $this->connection()->table(self::TABLE)->where('event_id', $dispatchedKey->eventId)->value('status'),
        );

        $store->claim($failedKey);
        $store->markFailed($failedKey);
        $this->assertSame(
            WebhookRecordStatus::Failed->value,
            $this->connection()->table(self::TABLE)->where('event_id', $failedKey->eventId)->value('status'),
        );
    }

    public function test_failed_claim_can_be_reclaimed_with_the_same_hash(): void
    {
        $key = $this->key();
        $store = $this->store();

        $store->claim($key);
        $store->markFailed($key);
        $result = $store->claim($key);

        $this->assertSame(WebhookClaimStatus::Claimed, $result->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $result->record?->status);
        $this->assertSame(
            WebhookRecordStatus::Claimed->value,
            $this->connection()->table(self::TABLE)->where('deduplication_key', $this->deduplicationKey($key))->value('status'),
        );
        $this->assertSame(1, $this->connection()->table(self::TABLE)->count());
    }

    public function test_different_hash_after_reclaim_is_a_collision(): void
    {
        $store = $this->store();
        $failedKey = $this->key('hash-1');
        $collisionKey = $this->key('hash-2');

        $store->claim($failedKey);
        $store->markFailed($failedKey);
        $this->assertSame(WebhookClaimStatus::Claimed, $store->claim($failedKey)->status);

        $result = $store->claim($collisionKey);

        $this->assertSame(WebhookClaimStatus::Collision, $result->status);
        $this->assertSame(WebhookRecordStatus::Claimed, $result->record?->status);
        $this->assertSame('hash-1', $result->record?->key->payloadHash);
    }

    public function test_mark_dispatched_rejects_a_failed_claim(): void
    {
        $key = $this->key();
        $store = $this->store();

        $store->claim($key);
        $store->markFailed($key);

        $this->expectException(WebhookStoreException::class);
        $store->markDispatched($key);
    }

    public function test_mark_failed_rejects_a_dispatched_claim(): void
    {
        $key = $this->key();
        $store = $this->store();

        $store->claim($key);
        $store->markDispatched($key);

        $this->expectException(WebhookStoreException::class);
        $store->markFailed($key);
    }

    public function test_invalid_table_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LaravelDatabaseWebhookIdempotencyStore($this->connection(), 'envai webhook!');
    }

    public function test_unique_violation_is_resolved_without_a_follow_up_write(): void
    {
        $key = $this->key();
        $insertBuilder = $this->createMock(Builder::class);
        $lookupBuilder = $this->createMock(Builder::class);
        $connection = $this->createMock(ConnectionInterface::class);
        $uniqueViolation = new QueryException(
            'sqlite',
            'insert into envai_webhook_receipts',
            [],
            new \PDOException('duplicate', 23000),
        );

        $connection->expects($this->exactly(2))
            ->method('table')
            ->with(self::TABLE)
            ->willReturnOnConsecutiveCalls($insertBuilder, $lookupBuilder);
        $insertBuilder->expects($this->once())
            ->method('insert')
            ->willThrowException($uniqueViolation);
        $lookupBuilder->method('where')->willReturnSelf();
        $lookupBuilder->expects($this->once())->method('first')->willReturn((object) [
            'deduplication_key' => $this->deduplicationKey($key),
            'payload_hash' => $key->payloadHash,
            'event_id' => $key->eventId,
            'event_type' => $key->eventType,
            'request_id' => $key->requestId,
            'status' => WebhookRecordStatus::Dispatched->value,
            'received_at' => '2026-08-30T10:00:00.000000+00:00',
            'updated_at' => '2026-08-30T10:00:00.000000+00:00',
        ]);
        $lookupBuilder->expects($this->never())->method('update');

        $result = (new LaravelDatabaseWebhookIdempotencyStore($connection))->claim($key);

        $this->assertSame(WebhookClaimStatus::Duplicate, $result->status);
        $this->assertSame(WebhookRecordStatus::Dispatched, $result->record?->status);
    }

    private function store(): LaravelDatabaseWebhookIdempotencyStore
    {
        return new LaravelDatabaseWebhookIdempotencyStore($this->connection());
    }

    private function connection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $this->fail('The database connection was not initialized.');
        }

        return $this->connection;
    }

    private function key(string $payloadHash = 'hash-1', string $eventId = 'evt_1'): WebhookIdempotencyKey
    {
        return new WebhookIdempotencyKey('account-1', $eventId, $payloadHash, 'created', 'req_1');
    }

    private function deduplicationKey(WebhookIdempotencyKey $key): string
    {
        return hash('sha256', strlen($key->scope).':'.$key->scope.strlen($key->eventId).':'.$key->eventId);
    }
}
