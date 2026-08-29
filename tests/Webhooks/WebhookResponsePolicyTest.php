<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Webhooks;

use Digitalygo\Envai\Webhooks\Contracts\WebhookResponsePolicy;
use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookOutcome;
use Digitalygo\Envai\Webhooks\WebhookProcessingResult;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use PHPUnit\Framework\TestCase;

final class WebhookResponsePolicyTest extends TestCase
{
    public function test_manager_has_no_default_response_policy(): void
    {
        $manager = new WebhookManager(
            new WebhookValidator,
            new RecordingVerifier,
            new RecordingStore,
            new RecordingDispatcher,
        );

        $this->expectException(\LogicException::class);
        $manager->responseFor(new WebhookProcessingResult(WebhookOutcome::Duplicate));
    }

    public function test_consumer_supplied_response_policy_is_opaque_to_the_core(): void
    {
        $policy = new class implements WebhookResponsePolicy
        {
            public function responseFor(WebhookProcessingResult $result): object
            {
                return new \stdClass;
            }
        };
        $manager = new WebhookManager(
            new WebhookValidator,
            new RecordingVerifier,
            new RecordingStore,
            new RecordingDispatcher,
            $policy,
        );

        $this->assertInstanceOf(\stdClass::class, $manager->responseFor(new WebhookProcessingResult(WebhookOutcome::Duplicate)));
    }
}
