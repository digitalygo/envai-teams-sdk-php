<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

use Digitalygo\Envai\Webhooks\Contracts\WebhookIdempotencyStore;

final class InMemoryWebhookIdempotencyStore implements WebhookIdempotencyStore
{
    /** @var array<string, WebhookIdempotencyRecord> */
    private array $records = [];

    public function claim(WebhookIdempotencyKey $key): WebhookClaimResult
    {
        $recordKey = self::recordKey($key);
        $existing = $this->records[$recordKey] ?? null;

        if ($existing === null) {
            $record = new WebhookIdempotencyRecord($key, WebhookRecordStatus::Claimed, self::now());
            $this->records[$recordKey] = $record;

            return new WebhookClaimResult(WebhookClaimStatus::Claimed, $record);
        }

        if ($existing->key->payloadHash !== $key->payloadHash) {
            return new WebhookClaimResult(WebhookClaimStatus::Collision, $existing);
        }

        if ($existing->status === WebhookRecordStatus::Claimed) {
            return new WebhookClaimResult(WebhookClaimStatus::AlreadyClaimed, $existing);
        }

        return new WebhookClaimResult(WebhookClaimStatus::Duplicate, $existing);
    }

    public function markDispatched(WebhookIdempotencyKey $key): void
    {
        $record = $this->record($key);

        if ($record->status === WebhookRecordStatus::Dispatched) {
            return;
        }

        if ($record->status !== WebhookRecordStatus::Claimed) {
            throw new WebhookStoreException('A failed webhook claim cannot be marked dispatched.');
        }

        $this->records[self::recordKey($key)] = new WebhookIdempotencyRecord($key, WebhookRecordStatus::Dispatched, self::now());
    }

    public function markFailed(WebhookIdempotencyKey $key): void
    {
        $record = $this->record($key);

        if ($record->status === WebhookRecordStatus::Failed) {
            return;
        }

        if ($record->status !== WebhookRecordStatus::Claimed) {
            throw new WebhookStoreException('A dispatched webhook claim cannot be marked failed.');
        }

        $this->records[self::recordKey($key)] = new WebhookIdempotencyRecord($key, WebhookRecordStatus::Failed, self::now());
    }

    /** @return list<WebhookIdempotencyRecord> */
    public function records(): array
    {
        return array_values($this->records);
    }

    private function record(WebhookIdempotencyKey $key): WebhookIdempotencyRecord
    {
        $record = $this->records[self::recordKey($key)] ?? null;

        if (! $record instanceof WebhookIdempotencyRecord) {
            throw new WebhookStoreException('Webhook claim does not exist.');
        }

        if ($record->key->payloadHash !== $key->payloadHash) {
            throw new WebhookStoreException('Webhook claim payload hash does not match the stored claim.');
        }

        return $record;
    }

    private static function recordKey(WebhookIdempotencyKey $key): string
    {
        return $key->scope."\0".$key->eventId;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
