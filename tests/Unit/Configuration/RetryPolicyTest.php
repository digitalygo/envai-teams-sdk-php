<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Configuration;

use Digitalygo\Envai\Configuration\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function test_no_jitter_delay_is_deterministic_exponential(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 200, maxDelayMs: 10_000, jitter: false);

        $this->assertSame(200, $policy->delayMs(1));
        $this->assertSame(400, $policy->delayMs(2));
        $this->assertSame(800, $policy->delayMs(3));
        $this->assertSame(1600, $policy->delayMs(4));
    }

    public function test_delay_is_capped_at_max_delay(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 1000, maxDelayMs: 5000, jitter: false);

        $this->assertSame(4000, $policy->delayMs(3));
        $this->assertSame(5000, $policy->delayMs(4));
        $this->assertSame(5000, $policy->delayMs(10));
    }

    public function test_retry_after_lifts_delay_but_stays_capped_at_max_delay(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 200, maxDelayMs: 10_000, jitter: false);

        // Server asks for 2 seconds, exponential would say 400 ms: the hint wins.
        $this->assertSame(2000, $policy->delayMs(2, retryAfterSeconds: 2.0));

        // Server asks for 60 seconds: the cap still wins.
        $this->assertSame(10_000, $policy->delayMs(1, retryAfterSeconds: 60.0));
    }

    public function test_retry_after_is_ignored_when_not_respected(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 200, maxDelayMs: 10_000, jitter: false, respectRetryAfter: false);

        $this->assertSame(200, $policy->delayMs(1, retryAfterSeconds: 30.0));
    }

    public function test_jitter_stays_within_bounds(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 1000, maxDelayMs: 10_000, jitter: true);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $delay = $policy->delayMs($attempt);
            $this->assertGreaterThanOrEqual(0, $delay);
            $this->assertLessThanOrEqual(10_000, $delay);
        }
    }

    public function test_rejects_zero_max_attempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetryPolicy(maxAttempts: 0);
    }

    public function test_retryable_statuses_default(): void
    {
        $policy = new RetryPolicy;

        $this->assertTrue($policy->isRetryableStatus(429));
        $this->assertTrue($policy->isRetryableStatus(502));
        $this->assertTrue($policy->isRetryableStatus(503));
        $this->assertTrue($policy->isRetryableStatus(504));
        $this->assertFalse($policy->isRetryableStatus(400));
        $this->assertFalse($policy->isRetryableStatus(500));
    }
}
