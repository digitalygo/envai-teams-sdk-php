<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

use Digitalygo\Envai\Models\Common\ApiError;

/**
 * Immutable result of one operation of an apply, identified by the
 * operation id (`<type>:<localKey>`). On success the remote identity
 * observed after the mutation is captured; on failure the error message
 * and, when the failure came from an HTTP response, the ApiError.
 */
final readonly class SyncOperationResult
{
    public function __construct(
        public string $operationId,
        public SyncOperationOutcome $outcome,
        public ?string $remoteId = null,
        public ?string $remoteName = null,
        public ?string $remoteMarker = null,
        public ?string $error = null,
        public ?ApiError $apiError = null,
    ) {}
}
