<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Compatibility;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

/**
 * Base of the Illuminate-dependent Laravel integration tests.
 *
 * The tests under tests/Integration/Laravel/ are written against the
 * real Illuminate API (database, support, console, config). The package
 * does not require Illuminate, so every concrete test class extends this
 * case and setUp() skips the whole class cleanly when the Illuminate
 * packages are absent. The Illuminate-free tests (LazySyncTest,
 * EnvaiFakeTest) extend SyncTestCase directly and always run.
 */
abstract class IntegrationTestCase extends SyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Compatibility::illuminateAvailable()) {
            $this->markTestSkipped(
                'The Illuminate packages are not installed in this environment; the Laravel integration tests require illuminate/database, illuminate/support, illuminate/console and illuminate/config (suggested by the package).',
            );
        }
    }
}
