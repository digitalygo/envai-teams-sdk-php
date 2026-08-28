<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Http;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Http\CancellationSource;
use PHPUnit\Framework\TestCase;

final class CancellationSourceTest extends TestCase
{
    public function test_starts_not_cancelled(): void
    {
        $source = new CancellationSource;

        $this->assertFalse($source->isCancelled());
        $this->assertFalse($source->cancelled());
        $source->throwIfCancelled();
        $this->addToAssertionCount(1);
    }

    public function test_cancel_flips_state(): void
    {
        $source = new CancellationSource;
        $source->cancel();

        $this->assertTrue($source->isCancelled());
        $this->assertTrue($source->cancelled());
    }

    public function test_throw_if_cancelled_throws_after_cancel(): void
    {
        $source = new CancellationSource;
        $source->cancel();

        $this->expectException(OperationCancelledException::class);

        $source->throwIfCancelled();
    }

    public function test_satisfies_cancellation_token_contract(): void
    {
        $source = new CancellationSource;

        $this->assertInstanceOf(CancellationToken::class, $source);
    }
}
