<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Webhooks;

use Digitalygo\Envai\Webhooks\WebhookIdempotencyKey;
use PHPUnit\Framework\TestCase;

final class WebhookIdempotencyKeyTest extends TestCase
{
    public function test_legacy_constructor_arguments_leave_optional_metadata_null(): void
    {
        $key = new WebhookIdempotencyKey('account-1', 'evt_1', 'hash');

        $this->assertNull($key->eventType);
        $this->assertNull($key->requestId);
    }

    public function test_optional_metadata_is_retained(): void
    {
        $key = new WebhookIdempotencyKey('account-1', 'evt_1', 'hash', 'created', 'req_1');

        $this->assertSame('created', $key->eventType);
        $this->assertSame('req_1', $key->requestId);
    }

    public function test_empty_optional_metadata_is_rejected(): void
    {
        $this->assertInvalid('', null);
        $this->assertInvalid(null, ' ');
    }

    public function test_control_characters_in_optional_metadata_are_rejected(): void
    {
        $this->assertInvalid("created\n", null);
        $this->assertInvalid(null, "req\0_1");
    }

    private function assertInvalid(?string $eventType, ?string $requestId): void
    {
        try {
            new WebhookIdempotencyKey('account-1', 'evt_1', 'hash', $eventType, $requestId);
            self::fail('Expected invalid optional metadata to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
