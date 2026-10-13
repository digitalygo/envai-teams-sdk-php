<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Wiki;

use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\UsageCost;
use Digitalygo\Envai\Models\Wiki\PhaseResult;
use Digitalygo\Envai\Webhooks\WebhookPayload;

abstract readonly class WikiWebhookEvent
{
    /** @param array<string, PhaseResult> $phases */
    protected function __construct(
        public string $eventId,
        public string $eventType,
        public string $workspaceId,
        public string $operationId,
        public string $occurredAt,
        public ?string $fileId,
        public ?int $sourceRevision,
        public ?string $canonicalRawPath,
        public ?string $previousCanonicalRawPath,
        public ?string $status,
        public ?string $finishedAt,
        public ?BillingState $billing,
        public ?UsageCost $usageCost,
        public array $phases,
        public WebhookPayload $payload,
    ) {}

    public static function fromPayload(WebhookPayload $payload): self
    {
        $data = Wire::stringKeyed($payload->toArray(), 'event', self::class);
        if (Wire::string($data, 'schema_version', self::class) !== '1') {
            throw new \InvalidArgumentException('Unsupported wiki webhook schema version.');
        }
        $type = Wire::string($data, 'event_type', self::class);
        $processed = $type === 'wiki.inbox.file.processed';
        $deleted = $type === 'wiki.outbox.file.deleted';
        $terminal = preg_match('/^wiki\.(inbox|outbox|sync)\.(completed|failed)$/D', $type) === 1;
        if (! $processed && ! $deleted && ! $terminal) {
            throw new \InvalidArgumentException('Unsupported wiki webhook event type.');
        }
        $fileId = null;
        $revision = null;
        if ($processed || $deleted) {
            $fileId = self::identity($data, 'file_id');
            $revision = Wire::int($data, 'source_revision', self::class);
            if ($revision < 1) {
                throw new \InvalidArgumentException('Invalid source revision.');
            }
            $field = $processed ? 'availability' : 'lifecycle';
            if (Wire::string($data, $field, self::class) !== ($processed ? 'raw' : 'deleted')) {
                throw new \InvalidArgumentException('Invalid file event state.');
            }
        }
        $status = null;
        $phases = [];
        if ($terminal) {
            $status = Wire::string($data, 'status', self::class);
            if (! str_ends_with($type, '.'.$status) || ! in_array($status, ['completed', 'failed'], true)) {
                throw new \InvalidArgumentException('Terminal status does not match event type.');
            }
            foreach (Wire::objectArray($data, 'phases', self::class) as $phase => $result) {
                if (! in_array($phase, ['inbox', 'outbox'], true) || ! is_array($result)) {
                    throw new \InvalidArgumentException('Invalid phase result.');
                }
                $phases[$phase] = PhaseResult::fromArray(Wire::stringKeyed($result, 'phase', self::class));
            }
        }

        $class = $processed ? WikiFileProcessedEvent::class : ($deleted ? WikiFileDeletedEvent::class : WikiOperationEvent::class);

        return new $class(
            self::identity($data, 'event_id'), $type,
            self::identity($data, 'workspace_id'), self::identity($data, 'operation_id'),
            Wire::string($data, 'occurred_at', self::class), $fileId, $revision,
            $processed ? Wire::string($data, 'canonical_raw_path', self::class) : null,
            $deleted ? Wire::nullableString($data, 'previous_canonical_raw_path', self::class) : null,
            $status, $terminal ? Wire::string($data, 'finished_at', self::class) : null,
            $terminal ? BillingState::fromArray(Wire::objectArray($data, 'billing', self::class)) : null,
            $terminal ? UsageCost::fromArray(Wire::objectArray($data, 'usage_cost', self::class)) : null,
            $phases, $payload,
        );
    }

    /** @param array<string, mixed> $data */
    private static function identity(array $data, string $key): string
    {
        $value = Wire::string($data, $key, self::class);
        if (preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid wiki event identity.');
        }

        return $value;
    }
}
