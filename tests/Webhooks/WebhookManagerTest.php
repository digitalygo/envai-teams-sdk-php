<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Webhooks;

use Digitalygo\Envai\Webhooks\Contracts\WebhookDispatcher;
use Digitalygo\Envai\Webhooks\Contracts\WebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\Contracts\WebhookVerifier;
use Digitalygo\Envai\Webhooks\InMemoryWebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\WebhookClaimResult;
use Digitalygo\Envai\Webhooks\WebhookClaimStatus;
use Digitalygo\Envai\Webhooks\WebhookIdempotencyKey;
use Digitalygo\Envai\Webhooks\WebhookIdentityException;
use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookOutcome;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Digitalygo\Envai\Webhooks\WebhookRecordStatus;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use Digitalygo\Envai\Webhooks\WebhookVerificationException;
use Digitalygo\Envai\Webhooks\WebhookVerificationResult;
use PHPUnit\Framework\TestCase;

final class WebhookManagerTest extends TestCase
{
    public function test_success_obeys_validation_verification_claim_dispatch_and_mark_order(): void
    {
        $order = [];
        $verifier = new RecordingVerifier($order);
        $store = new RecordingStore($order);
        $dispatcher = new RecordingDispatcher($order);
        $manager = new WebhookManager(new WebhookValidator, $verifier, $store, $dispatcher);

        $result = $manager->process($this->request('{"event_id":"evt_1","type":"created"}'), 'account-1');

        $this->assertSame(WebhookOutcome::Dispatched, $result->outcome);
        $this->assertSame(['verify', 'claim', 'dispatch', 'mark_dispatched'], $order);
        $this->assertCount(1, $dispatcher->events);
        $this->assertNotNull($result->event);
        $this->assertSame(hash('sha256', '{"event_id":"evt_1","type":"created"}'), $result->payloadHash);
        $this->assertSame('evt_1', $result->event?->eventId);
        $this->assertSame('created', $store->claimedKey?->eventType);
        $this->assertSame('req_1', $store->claimedKey?->requestId);
    }

    public function test_verifier_failure_has_no_store_or_dispatch_side_effect(): void
    {
        $verifier = new RecordingVerifier;
        $verifier->failure = new WebhookVerificationException('verification failed');
        $store = new RecordingStore;
        $dispatcher = new RecordingDispatcher;
        $result = $this->manager($verifier, $store, $dispatcher)->process($this->request('{}'), 'account-1');

        $this->assertSame(WebhookOutcome::VerificationFailed, $result->outcome);
        $this->assertSame(0, $store->claimCalls);
        $this->assertCount(0, $dispatcher->events);
    }

    public function test_missing_identity_has_no_store_or_dispatch_side_effect(): void
    {
        $verifier = new RecordingVerifier;
        $verifier->failure = new WebhookIdentityException('identity missing');
        $store = new RecordingStore;
        $dispatcher = new RecordingDispatcher;
        $result = $this->manager($verifier, $store, $dispatcher)->process($this->request('{}'), 'account-1');

        $this->assertSame(WebhookOutcome::IdentityInvalid, $result->outcome);
        $this->assertSame(0, $store->claimCalls);
        $this->assertCount(0, $dispatcher->events);
    }

    public function test_invalid_request_has_no_verifier_store_or_dispatch_side_effect(): void
    {
        $verifier = new RecordingVerifier;
        $store = new RecordingStore;
        $dispatcher = new RecordingDispatcher;
        $result = $this->manager($verifier, $store, $dispatcher)->process($this->request('[]'), 'account-1');

        $this->assertSame(WebhookOutcome::ValidationFailed, $result->outcome);
        $this->assertSame(0, $verifier->calls);
        $this->assertSame(0, $store->claimCalls);
        $this->assertCount(0, $dispatcher->events);
    }

    public function test_store_claim_failure_has_no_dispatch_side_effect(): void
    {
        $verifier = new RecordingVerifier;
        $store = new RecordingStore;
        $store->claimFailure = new \RuntimeException('store unavailable');
        $dispatcher = new RecordingDispatcher;
        $result = $this->manager($verifier, $store, $dispatcher)->process($this->request('{}'), 'account-1');

        $this->assertSame(WebhookOutcome::StoreFailed, $result->outcome);
        $this->assertCount(0, $dispatcher->events);
    }

    public function test_concurrent_claim_is_rejected_without_dispatch(): void
    {
        $store = new RecordingStore;
        $store->claimStatus = WebhookClaimStatus::AlreadyClaimed;
        $dispatcher = new RecordingDispatcher;
        $result = $this->manager(new RecordingVerifier, $store, $dispatcher)->process($this->request('{}'), 'account-1');

        $this->assertSame(WebhookOutcome::ClaimContended, $result->outcome);
        $this->assertCount(0, $dispatcher->events);
        $this->assertSame(0, $store->markDispatchedCalls);
        $this->assertSame(0, $store->markFailedCalls);
    }

    public function test_identical_duplicate_is_not_dispatched_twice(): void
    {
        $store = new InMemoryWebhookIdempotencyStore;
        $dispatcher = new RecordingDispatcher;
        $manager = $this->manager(new RecordingVerifier, $store, $dispatcher);
        $request = $this->request('{"event":"same"}');

        $first = $manager->process($request, 'account-1');
        $second = $manager->process($request, 'account-1');

        $this->assertSame(WebhookOutcome::Dispatched, $first->outcome);
        $this->assertSame(WebhookOutcome::Duplicate, $second->outcome);
        $this->assertCount(1, $dispatcher->events);
    }

    public function test_same_scope_and_event_id_with_different_raw_hash_is_collision(): void
    {
        $store = new InMemoryWebhookIdempotencyStore;
        $dispatcher = new RecordingDispatcher;
        $manager = $this->manager(new RecordingVerifier, $store, $dispatcher);

        $first = $manager->process($this->request('{"event":"same"}'), 'account-1');
        $second = $manager->process($this->request('{"event": "same"}'), 'account-1');

        $this->assertSame(WebhookOutcome::Dispatched, $first->outcome);
        $this->assertSame(WebhookOutcome::Collision, $second->outcome);
        $this->assertNotSame($first->payloadHash, $second->payloadHash);
        $this->assertCount(1, $dispatcher->events);
    }

    public function test_different_scopes_do_not_collide(): void
    {
        $store = new InMemoryWebhookIdempotencyStore;
        $dispatcher = new RecordingDispatcher;
        $manager = $this->manager(new RecordingVerifier, $store, $dispatcher);
        $request = $this->request('{}');

        $first = $manager->process($request, 'account-1');
        $second = $manager->process($request, 'account-2');

        $this->assertSame(WebhookOutcome::Dispatched, $first->outcome);
        $this->assertSame(WebhookOutcome::Dispatched, $second->outcome);
        $this->assertCount(2, $dispatcher->events);
    }

    public function test_dispatch_failure_marks_claim_failed_and_emits_no_success_event(): void
    {
        $store = new InMemoryWebhookIdempotencyStore;
        $dispatcher = new RecordingDispatcher;
        $dispatcher->failure = new \RuntimeException('dispatch failed');
        $manager = $this->manager(new RecordingVerifier, $store, $dispatcher);

        $result = $manager->process($this->request('{}'), 'account-1');

        $this->assertSame(WebhookOutcome::DispatchFailed, $result->outcome);
        $this->assertNull($result->event);
        $this->assertSame(WebhookRecordStatus::Failed, $store->records()[0]->status);
        $this->assertCount(1, $dispatcher->events);
    }

    public function test_mark_dispatched_failure_is_explicit_and_does_not_retry_dispatch(): void
    {
        $store = new RecordingStore;
        $store->markDispatchedFailure = new \RuntimeException('mark failed');
        $dispatcher = new RecordingDispatcher;
        $manager = $this->manager(new RecordingVerifier, $store, $dispatcher);

        $result = $manager->process($this->request('{}'), 'account-1');

        $this->assertSame(WebhookOutcome::MarkDispatchedFailed, $result->outcome);
        $this->assertCount(1, $dispatcher->events);
        $this->assertSame(1, $store->markDispatchedCalls);
    }

    public function test_sensitive_request_data_is_not_present_in_received_event(): void
    {
        $dispatcher = new RecordingDispatcher;
        $request = new WebhookRequest(
            'POST',
            '/webhooks',
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'opaque-auth-value',
                'X-Signature' => 'opaque-signature-value',
            ],
            '{"event":"safe"}',
        );
        $result = $this->manager(new RecordingVerifier, new InMemoryWebhookIdempotencyStore, $dispatcher)->process($request, 'account-1');
        $encoded = json_encode($result->event, JSON_THROW_ON_ERROR);

        $this->assertSame(WebhookOutcome::Dispatched, $result->outcome);
        $this->assertStringNotContainsString('opaque-auth-value', $encoded);
        $this->assertStringNotContainsString('opaque-signature-value', $encoded);
        $this->assertStringNotContainsString('authorization', strtolower($encoded));
        $this->assertStringNotContainsString('signature', strtolower($encoded));
        $this->assertStringNotContainsString('raw_body', strtolower($encoded));
        $this->assertStringNotContainsString('token', strtolower($encoded));
    }

    public function test_empty_scope_fails_closed_before_verification(): void
    {
        $verifier = new RecordingVerifier;
        $store = new RecordingStore;
        $dispatcher = new RecordingDispatcher;

        $result = $this->manager($verifier, $store, $dispatcher)->process($this->request('{}'), '   ');

        $this->assertSame(WebhookOutcome::InvalidScope, $result->outcome);
        $this->assertSame(0, $verifier->calls);
        $this->assertSame(0, $store->claimCalls);
        $this->assertCount(0, $dispatcher->events);
    }

    private function manager(WebhookVerifier $verifier, WebhookIdempotencyStore $store, WebhookDispatcher $dispatcher): WebhookManager
    {
        return new WebhookManager(new WebhookValidator, $verifier, $store, $dispatcher);
    }

    private function request(string $body): WebhookRequest
    {
        return new WebhookRequest('POST', '/webhooks', ['Content-Type' => 'application/json'], $body);
    }
}

final class RecordingVerifier implements WebhookVerifier
{
    public int $calls = 0;

    public ?\Throwable $failure = null;

    public array $order;

    public function __construct(?array &$order = null)
    {
        if ($order === null) {
            $this->order = [];

            return;
        }

        $this->order = &$order;
    }

    public function verify(WebhookRequest $request, WebhookPayload $payload): WebhookVerificationResult
    {
        $this->calls++;
        $this->order[] = 'verify';

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new WebhookVerificationResult('evt_1', 'created', requestId: 'req_1');
    }
}

final class RecordingDispatcher implements WebhookDispatcher
{
    public array $events = [];

    public ?\Throwable $failure = null;

    public array $order;

    public function __construct(?array &$order = null)
    {
        if ($order === null) {
            $this->order = [];

            return;
        }

        $this->order = &$order;
    }

    public function dispatch(WebhookReceived $event): void
    {
        $this->order[] = 'dispatch';
        $this->events[] = $event;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

final class RecordingStore implements WebhookIdempotencyStore
{
    public int $claimCalls = 0;

    public ?WebhookIdempotencyKey $claimedKey = null;

    public int $markDispatchedCalls = 0;

    public int $markFailedCalls = 0;

    public ?\Throwable $claimFailure = null;

    public ?\Throwable $markDispatchedFailure = null;

    public WebhookClaimStatus $claimStatus = WebhookClaimStatus::Claimed;

    public array $order;

    public function __construct(?array &$order = null)
    {
        if ($order === null) {
            $this->order = [];

            return;
        }

        $this->order = &$order;
    }

    public function claim(WebhookIdempotencyKey $key): WebhookClaimResult
    {
        $this->claimCalls++;
        $this->claimedKey = $key;
        $this->order[] = 'claim';

        if ($this->claimFailure !== null) {
            throw $this->claimFailure;
        }

        return new WebhookClaimResult($this->claimStatus);
    }

    public function markDispatched(WebhookIdempotencyKey $key): void
    {
        $this->markDispatchedCalls++;
        $this->order[] = 'mark_dispatched';

        if ($this->markDispatchedFailure !== null) {
            throw $this->markDispatchedFailure;
        }
    }

    public function markFailed(WebhookIdempotencyKey $key): void
    {
        $this->markFailedCalls++;
        $this->order[] = 'mark_failed';
    }
}
