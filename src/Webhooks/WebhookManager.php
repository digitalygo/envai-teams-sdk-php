<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

use Digitalygo\Envai\Webhooks\Contracts\WebhookDispatcher;
use Digitalygo\Envai\Webhooks\Contracts\WebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\Contracts\WebhookResponsePolicy;
use Digitalygo\Envai\Webhooks\Contracts\WebhookVerifier;

final readonly class WebhookManager
{
    public function __construct(
        private WebhookValidator $validator,
        private WebhookVerifier $verifier,
        private WebhookIdempotencyStore $idempotencyStore,
        private WebhookDispatcher $dispatcher,
        private ?WebhookResponsePolicy $responsePolicy = null,
        private ?string $scope = null,
    ) {}

    public function process(WebhookRequest $request, ?string $scope = null): WebhookProcessingResult
    {
        $scope = $scope ?? $this->scope;

        if ($scope === null || trim($scope) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $scope) === 1) {
            return new WebhookProcessingResult(WebhookOutcome::InvalidScope);
        }

        try {
            $payload = $this->validator->validate($request);
        } catch (\Throwable) {
            return new WebhookProcessingResult(WebhookOutcome::ValidationFailed);
        }

        try {
            $verification = $this->verifier->verify($request, $payload);
        } catch (WebhookIdentityException) {
            return new WebhookProcessingResult(WebhookOutcome::IdentityInvalid);
        } catch (\Throwable) {
            return new WebhookProcessingResult(WebhookOutcome::VerificationFailed);
        }

        $payloadHash = hash('sha256', $request->rawBody);

        try {
            $key = new WebhookIdempotencyKey(
                scope: $scope,
                eventId: $verification->eventId,
                payloadHash: $payloadHash,
                eventType: $verification->eventType,
                requestId: $verification->requestId,
            );
            $claim = $this->idempotencyStore->claim($key);
        } catch (\Throwable) {
            return new WebhookProcessingResult(WebhookOutcome::StoreFailed, $payloadHash);
        }

        return match ($claim->status) {
            WebhookClaimStatus::Duplicate => new WebhookProcessingResult(WebhookOutcome::Duplicate, $payloadHash),
            WebhookClaimStatus::Collision => new WebhookProcessingResult(WebhookOutcome::Collision, $payloadHash),
            WebhookClaimStatus::AlreadyClaimed => new WebhookProcessingResult(WebhookOutcome::ClaimContended, $payloadHash),
            WebhookClaimStatus::Claimed => $this->dispatchClaim($key, $verification, $payload, $payloadHash),
        };
    }

    public function handle(WebhookRequest $request, ?string $scope = null): WebhookProcessingResult
    {
        return $this->process($request, $scope);
    }

    public function responseFor(WebhookProcessingResult $result): object
    {
        if ($this->responsePolicy === null) {
            throw new \LogicException('No WebhookResponsePolicy has been configured.');
        }

        return $this->responsePolicy->responseFor($result);
    }

    private function dispatchClaim(
        WebhookIdempotencyKey $key,
        WebhookVerificationResult $verification,
        WebhookPayload $payload,
        string $payloadHash,
    ): WebhookProcessingResult {
        try {
            $event = new WebhookReceived(
                eventId: $verification->eventId,
                eventType: $verification->eventType,
                payload: $payload,
                timestamp: $verification->timestamp,
                requestId: $verification->requestId,
                verificationMetadata: $verification->metadata,
            );
        } catch (\Throwable) {
            $this->markFailed($key);

            return new WebhookProcessingResult(WebhookOutcome::IdentityInvalid, $payloadHash);
        }

        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable) {
            try {
                $this->idempotencyStore->markFailed($key);
            } catch (\Throwable) {
                return new WebhookProcessingResult(WebhookOutcome::MarkFailed, $payloadHash);
            }

            return new WebhookProcessingResult(WebhookOutcome::DispatchFailed, $payloadHash);
        }

        try {
            $this->idempotencyStore->markDispatched($key);
        } catch (\Throwable) {
            return new WebhookProcessingResult(WebhookOutcome::MarkDispatchedFailed, $payloadHash);
        }

        return new WebhookProcessingResult(WebhookOutcome::Dispatched, $payloadHash, $event);
    }

    private function markFailed(WebhookIdempotencyKey $key): void
    {
        try {
            $this->idempotencyStore->markFailed($key);
        } catch (\Throwable) {
        }
    }
}
