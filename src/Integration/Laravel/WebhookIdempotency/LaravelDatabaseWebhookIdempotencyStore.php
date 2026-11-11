<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\WebhookIdempotency;

use Digitalygo\Envai\Webhooks\Contracts\WebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\WebhookClaimResult;
use Digitalygo\Envai\Webhooks\WebhookClaimStatus;
use Digitalygo\Envai\Webhooks\WebhookIdempotencyKey;
use Digitalygo\Envai\Webhooks\WebhookIdempotencyRecord;
use Digitalygo\Envai\Webhooks\WebhookRecordStatus;
use Digitalygo\Envai\Webhooks\WebhookStoreException;
use Illuminate\Database\ConnectionInterface;

final class LaravelDatabaseWebhookIdempotencyStore implements WebhookIdempotencyStore
{
    private const TIME_FORMAT = 'Y-m-d\\TH:i:s.uP';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'envai_webhook_receipts',
    ) {
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException('The webhook receipt table must be a non-empty SQL identifier (letters, digits, underscores).');
        }
    }

    public function claim(WebhookIdempotencyKey $key): WebhookClaimResult
    {
        $deduplicationKey = self::deduplicationKey($key);
        $claimedAt = $this->now();

        try {
            try {
                $this->connection->table($this->table)->insert([
                    'deduplication_key' => $deduplicationKey,
                    'payload_hash' => $key->payloadHash,
                    'event_id' => $key->eventId,
                    'event_type' => $key->eventType,
                    'request_id' => $key->requestId,
                    'status' => WebhookRecordStatus::Claimed->value,
                    'received_at' => $claimedAt->format(self::TIME_FORMAT),
                    'updated_at' => $claimedAt->format(self::TIME_FORMAT),
                ]);

                return new WebhookClaimResult(
                    WebhookClaimStatus::Claimed,
                    new WebhookIdempotencyRecord($key, WebhookRecordStatus::Claimed, $claimedAt),
                );
            } catch (\Throwable $exception) {
                if (! self::isUniqueViolation($exception)) {
                    throw $exception;
                }
            }

            $row = $this->find($deduplicationKey);

            if ($row === null) {
                throw new WebhookStoreException('The webhook receipt could not be read after a unique constraint violation.');
            }

            return $this->resolveClaim($key, $deduplicationKey, $row);
        } catch (WebhookStoreException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new WebhookStoreException('Could not claim the webhook receipt.', previous: $exception);
        }
    }

    public function markDispatched(WebhookIdempotencyKey $key): void
    {
        $this->mark($key, WebhookRecordStatus::Dispatched);
    }

    public function markFailed(WebhookIdempotencyKey $key): void
    {
        $this->mark($key, WebhookRecordStatus::Failed);
    }

    /** @param array<string, mixed> $row */
    private function resolveClaim(WebhookIdempotencyKey $key, string $deduplicationKey, array $row): WebhookClaimResult
    {
        $record = $this->recordFromRow($key, $row);

        if ($record->key->eventId !== $key->eventId || self::rowString($row, 'deduplication_key') !== $deduplicationKey) {
            return new WebhookClaimResult(WebhookClaimStatus::Collision, $record);
        }

        if ($record->key->payloadHash !== $key->payloadHash) {
            return new WebhookClaimResult(WebhookClaimStatus::Collision, $record);
        }

        return match ($record->status) {
            WebhookRecordStatus::Dispatched => new WebhookClaimResult(WebhookClaimStatus::Duplicate, $record),
            WebhookRecordStatus::Claimed => new WebhookClaimResult(WebhookClaimStatus::AlreadyClaimed, $record),
            WebhookRecordStatus::Failed => $this->reclaimFailed($key, $deduplicationKey),
        };
    }

    private function reclaimFailed(WebhookIdempotencyKey $key, string $deduplicationKey): WebhookClaimResult
    {
        $claimedAt = $this->now();
        $updated = $this->connection->table($this->table)
            ->where('deduplication_key', $deduplicationKey)
            ->where('event_id', $key->eventId)
            ->where('payload_hash', $key->payloadHash)
            ->where('status', WebhookRecordStatus::Failed->value)
            ->update([
                'status' => WebhookRecordStatus::Claimed->value,
                'updated_at' => $claimedAt->format(self::TIME_FORMAT),
            ]);

        if ($updated === 1) {
            return new WebhookClaimResult(
                WebhookClaimStatus::Claimed,
                new WebhookIdempotencyRecord($key, WebhookRecordStatus::Claimed, $claimedAt),
            );
        }

        $row = $this->find($deduplicationKey);

        if ($row === null) {
            throw new WebhookStoreException('The webhook receipt disappeared while it was being reclaimed.');
        }

        $record = $this->recordFromRow($key, $row);

        if ($record->key->eventId !== $key->eventId || $record->key->payloadHash !== $key->payloadHash) {
            return new WebhookClaimResult(WebhookClaimStatus::Collision, $record);
        }

        return match ($record->status) {
            WebhookRecordStatus::Dispatched => new WebhookClaimResult(WebhookClaimStatus::Duplicate, $record),
            WebhookRecordStatus::Claimed => new WebhookClaimResult(WebhookClaimStatus::AlreadyClaimed, $record),
            WebhookRecordStatus::Failed => throw new WebhookStoreException('The failed webhook receipt could not be reclaimed atomically.'),
        };
    }

    private function mark(WebhookIdempotencyKey $key, WebhookRecordStatus $targetStatus): void
    {
        $deduplicationKey = self::deduplicationKey($key);
        $updatedAt = $this->now()->format(self::TIME_FORMAT);

        try {
            $updated = $this->connection->table($this->table)
                ->where('deduplication_key', $deduplicationKey)
                ->where('event_id', $key->eventId)
                ->where('payload_hash', $key->payloadHash)
                ->where('status', WebhookRecordStatus::Claimed->value)
                ->update([
                    'status' => $targetStatus->value,
                    'updated_at' => $updatedAt,
                ]);

            if ($updated === 1) {
                return;
            }

            $row = $this->find($deduplicationKey);

            if ($row === null) {
                throw new WebhookStoreException('Webhook claim does not exist.');
            }

            $record = $this->recordFromRow($key, $row);

            if ($record->key->eventId !== $key->eventId || $record->key->payloadHash !== $key->payloadHash) {
                throw new WebhookStoreException('Webhook claim payload hash does not match the stored claim.');
            }

            if ($record->status === $targetStatus) {
                return;
            }

            if ($targetStatus === WebhookRecordStatus::Dispatched && $record->status === WebhookRecordStatus::Failed) {
                throw new WebhookStoreException('A failed webhook claim cannot be marked dispatched.');
            }

            if ($targetStatus === WebhookRecordStatus::Failed && $record->status === WebhookRecordStatus::Dispatched) {
                throw new WebhookStoreException('A dispatched webhook claim cannot be marked failed.');
            }

            throw new WebhookStoreException(sprintf(
                'The webhook claim could not be marked %s from status %s.',
                $targetStatus->value,
                $record->status->value,
            ));
        } catch (WebhookStoreException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new WebhookStoreException('Could not update the webhook receipt.', previous: $exception);
        }
    }

    /** @return array<string, mixed>|null */
    private function find(string $deduplicationKey): ?array
    {
        $row = $this->connection->table($this->table)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if ($row === null) {
            return null;
        }
        $result = [];
        foreach ((array) $row as $key => $value) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('Webhook receipt has an invalid column name.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function recordFromRow(WebhookIdempotencyKey $requestedKey, array $row): WebhookIdempotencyRecord
    {
        $storedKey = new WebhookIdempotencyKey(
            scope: $requestedKey->scope,
            eventId: self::rowString($row, 'event_id'),
            payloadHash: self::rowString($row, 'payload_hash'),
            eventType: self::nullableRowString($row, 'event_type'),
            requestId: self::nullableRowString($row, 'request_id'),
        );

        if (self::deduplicationKey($storedKey) !== self::rowString($row, 'deduplication_key')) {
            throw new WebhookStoreException('The webhook receipt has an invalid deduplication key.');
        }

        $statusValue = self::rowString($row, 'status');
        $status = WebhookRecordStatus::tryFrom($statusValue);

        if ($status === null) {
            throw new WebhookStoreException(sprintf('The webhook receipt has an unknown status "%s".', $statusValue));
        }

        return new WebhookIdempotencyRecord(
            key: $storedKey,
            status: $status,
            updatedAt: $this->parseTimestamp(self::rowString($row, 'updated_at')),
        );
    }

    private function parseTimestamp(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new WebhookStoreException('The webhook receipt has an invalid updated_at timestamp.', previous: $exception);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function deduplicationKey(WebhookIdempotencyKey $key): string
    {
        $encoded = strlen($key->scope).':'.$key->scope.strlen($key->eventId).':'.$key->eventId;

        return hash('sha256', $encoded);
    }

    private static function isUniqueViolation(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException) {
                $errorInfo = $current->errorInfo;

                if (is_array($errorInfo)) {
                    $sqlState = $errorInfo[0] ?? null;

                    if (is_string($sqlState) && str_starts_with($sqlState, '23')) {
                        return true;
                    }

                    $driverCode = $errorInfo[1] ?? null;

                    if (is_int($driverCode) && in_array($driverCode, [19, 1022, 1062, 1169, 23000, 23505], true)) {
                        return true;
                    }
                }
            }

            $code = $current->getCode();

            if (is_string($code) && str_starts_with($code, '23')) {
                return true;
            }

            if (is_int($code) && in_array($code, [19, 1022, 1062, 1169, 23000, 23505], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private static function rowString(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value) || $value === '') {
            throw new WebhookStoreException(sprintf('The webhook receipt has an invalid "%s" value.', $column));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableRowString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new WebhookStoreException(sprintf('The webhook receipt has an invalid "%s" value.', $column));
        }

        return $value;
    }
}
