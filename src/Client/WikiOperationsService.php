<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\Poller;
use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Wiki\Categories;
use Digitalygo\Envai\Models\Wiki\CategoryIndex;
use Digitalygo\Envai\Models\Wiki\CategoryIndexRequest;
use Digitalygo\Envai\Models\Wiki\WikiOperation;
use Digitalygo\Envai\Models\Wiki\WikiRunRequest;
use Digitalygo\Envai\Models\Wiki\WikiSource;

final readonly class WikiOperationsService
{
    private TypedRequester $requests;

    public function __construct(private RequestExecutor $executor, private UriResolver $uris, private int $connectTimeoutSeconds = 10, private int $requestTimeoutSeconds = 60)
    {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    /** Waits for an existing resource; never submits a generation or Wiki run. */
    public function waitForOperation(string $workspaceId, string $operationId, float $timeoutSeconds = 300, int $intervalMilliseconds = 1000, ?CancellationToken $token = null): WikiOperation
    {
        return Poller::wait(
            fn (int $remaining): WikiOperation => (new self($this->executor->withoutRetries(), $this->uris, min($this->connectTimeoutSeconds, $remaining), min($this->requestTimeoutSeconds, $remaining)))->operation($workspaceId, $operationId),
            static fn (WikiOperation $value): bool => in_array($value->status, ['completed', 'failed'], true),
            $timeoutSeconds, $intervalMilliseconds, $token,
        );
    }

    public function inbox(string $workspaceId, WikiRunRequest $request, string $idempotencyKey): WikiOperation
    {
        $result = $this->requests->request('POST', $this->path($workspaceId, 'inbox/runs'), [], $request->toArray(), WikiOperation::class, EnvelopeMode::Envelope, retry: RetrySafety::NeverRetry, idempotencyKey: $idempotencyKey);

        /** @var WikiOperation $result */
        return $result;
    }

    public function outbox(string $workspaceId, WikiRunRequest $request, string $idempotencyKey): WikiOperation
    {
        $result = $this->requests->request('POST', $this->path($workspaceId, 'outbox/runs'), [], $request->toArray(), WikiOperation::class, EnvelopeMode::Envelope, retry: RetrySafety::NeverRetry, idempotencyKey: $idempotencyKey);

        /** @var WikiOperation $result */
        return $result;
    }

    public function sync(string $workspaceId, WikiRunRequest $request, string $idempotencyKey): WikiOperation
    {
        $result = $this->requests->request('POST', $this->path($workspaceId, 'syncs'), [], $request->toArray(), WikiOperation::class, EnvelopeMode::Envelope, retry: RetrySafety::NeverRetry, idempotencyKey: $idempotencyKey);

        /** @var WikiOperation $result */
        return $result;
    }

    public function operation(string $workspaceId, string $operationId): WikiOperation
    {
        $result = $this->requests->request('GET', $this->path($workspaceId, 'operations/'.rawurlencode($operationId)), [], null, WikiOperation::class, EnvelopeMode::Envelope);

        /** @var WikiOperation $result */
        return $result;
    }

    public function withdraw(string $workspaceId, string $fileId): WikiSource
    {
        $result = $this->requests->request('POST', $this->path($workspaceId, 'outbox/files'), [], ['file_id' => $fileId], WikiSource::class, EnvelopeMode::Envelope, retry: RetrySafety::NeverRetry);

        /** @var WikiSource $result */
        return $result;
    }

    public function categories(string $workspaceId): Categories
    {
        $result = $this->requests->request('GET', $this->path($workspaceId, 'categories'), [], null, Categories::class, EnvelopeMode::Envelope);

        /** @var Categories $result */
        return $result;
    }

    public function categoryIndex(string $workspaceId, string $categoryId, CategoryIndexRequest $request): CategoryIndex
    {
        $result = $this->requests->request('GET', $this->path($workspaceId, 'categories/'.rawurlencode($categoryId).'/index'), $request->toArray(), null, CategoryIndex::class, EnvelopeMode::Envelope);

        /** @var CategoryIndex $result */
        return $result;
    }

    private function path(string $workspaceId, string $suffix): string
    {
        return '/workspaces/'.rawurlencode($workspaceId).'/wiki/'.$suffix;
    }
}
