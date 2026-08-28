<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

use Digitalygo\Envai\Models\Common\ApiError;

/**
 * Immutable machine-readable report of one apply, with a stable JSON
 * shape (see toArray()) suitable for the CLI `--json` output and for
 * automation. Enum values are serialized as their string values and the
 * report carries an explicit report_format version.
 */
final readonly class SyncReport
{
    /**
     * @param  list<SyncOperationResult>  $results  One result per plan operation, in plan order.
     * @param  array<string, int>  $summary  Outcome counts keyed by outcome value, plus `total`.
     */
    public function __construct(
        public string $connection,
        public string $manifestHash,
        public string $planHash,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
        public SyncReportStatus $status,
        public array $results,
        public bool $manifestReady,
        public array $summary,
    ) {}

    /**
     * Serializes the report to the stable machine-readable shape. The
     * report_format is always 1; dates are ISO 8601; api_error keeps the
     * {type, code, message, details, extra} envelope when present.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'report_format' => 1,
            'connection' => $this->connection,
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'finished_at' => $this->finishedAt->format(\DateTimeInterface::ATOM),
            'status' => $this->status->value,
            'manifest_ready' => $this->manifestReady,
            'results' => array_map(static fn (SyncOperationResult $result): array => [
                'operation_id' => $result->operationId,
                'outcome' => $result->outcome->value,
                'remote_id' => $result->remoteId,
                'remote_name' => $result->remoteName,
                'remote_marker' => $result->remoteMarker,
                'error' => $result->error,
                'api_error' => self::serializeApiError($result->apiError),
            ], $this->results),
            'summary' => $this->summary,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serializeApiError(?ApiError $apiError): ?array
    {
        if ($apiError === null) {
            return null;
        }

        return [
            'type' => $apiError->type,
            'code' => $apiError->code,
            'message' => $apiError->message,
            'details' => $apiError->details,
            'extra' => $apiError->extra,
        ];
    }
}
