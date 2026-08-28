<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Contract;

use Digitalygo\Envai\Sync\State\File\FileSyncStateStore;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * Runs the full SyncStateStore contract suite against the file store on
 * a temporary directory fixture (project document section 29.5). Each
 * test method gets its own fresh temp directory; makeStore() re-creates
 * store instances over the same path to prove durability.
 */
final class FileSyncStateStoreContractTest extends SyncStateStoreContractTestCase
{
    private string $directory = '';

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/atp-file-state-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
        $this->path = $this->directory.'/state.json';
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            $this->removeDirectory($this->directory);
        }

        parent::tearDown();
    }

    protected function makeStore(float $lockTimeoutSeconds = 5.0): SyncStateStore
    {
        return new FileSyncStateStore($this->path, lockTimeoutSeconds: $lockTimeoutSeconds);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*', GLOB_NOSORT) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }
}
