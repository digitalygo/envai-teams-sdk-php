<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\State;

use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Tools\State\ClientToolCallStatus;
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use Digitalygo\Envai\Tools\State\ToolCallStatus;
use PHPUnit\Framework\TestCase;

final class FileClientToolCallStoreTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/atp-tool-store-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->path = $this->directory.'/calls.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function identity(string $callId = 'call_01', string $chatId = 'cht_01', ?string $turnId = 'cmpl_01'): ToolCallIdentity
    {
        return new ToolCallIdentity($chatId, $callId, 'lookup_order', $turnId);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    public function test_begin_is_persisted_across_store_instances(): void
    {
        (new FileClientToolCallStore($this->path))->begin($this->identity());

        $second = new FileClientToolCallStore($this->path);
        $begin = $second->begin($this->identity());

        $this->assertSame(ToolCallStatus::AlreadyRunning, $begin->status);

        $previous = $begin->previous;
        $this->assertNotNull($previous);
        $this->assertSame(ClientToolCallStatus::Started, $previous->status);
        $this->assertSame('lookup_order', $previous->identity->toolName);
    }

    public function test_crash_boundary_begin_then_resolve_after_reinstantiation(): void
    {
        $storeA = new FileClientToolCallStore($this->path);
        $beginA = $storeA->begin($this->identity());
        $this->assertSame(ToolCallStatus::Started, $beginA->status);

        // Simulate a crash before any mark: a new store instance from the
        // same file sees the claim and the at-most-once guarantee holds.
        $storeB = new FileClientToolCallStore($this->path);
        $beginB = $storeB->begin($this->identity());
        $this->assertSame(ToolCallStatus::AlreadyRunning, $beginB->status);

        $storeB->markSubmitted($this->identity());
        $storeB->markResolved($this->identity());

        $storeC = new FileClientToolCallStore($this->path);
        $beginC = $storeC->begin($this->identity());

        $this->assertSame(ToolCallStatus::AlreadyResolved, $beginC->status);
        $this->assertSame(ClientToolCallStatus::Resolved, $beginC->previous?->status);
    }

    public function test_atomic_write_produces_a_versioned_state_file_without_temp_leftovers(): void
    {
        (new FileClientToolCallStore($this->path))->begin($this->identity());

        $this->assertFileExists($this->path);

        $decoded = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['format']);
        $this->assertArrayHasKey('calls', $decoded);
        $this->assertSame([], glob($this->path.'.tmp.*') ?: []);

        $permissions = fileperms($this->path) & 0777;
        $this->assertSame(0600, $permissions);
    }

    public function test_marks_are_visible_across_instances(): void
    {
        $identity = $this->identity();
        (new FileClientToolCallStore($this->path))->begin($identity);
        (new FileClientToolCallStore($this->path))->markSubmitted($identity);
        (new FileClientToolCallStore($this->path))->markResolved($identity);

        $records = (new FileClientToolCallStore($this->path))->records();

        $this->assertCount(1, $records);
        $this->assertSame(ClientToolCallStatus::Resolved, $records[0]->status);
    }

    public function test_begin_blocks_while_another_process_holds_the_lock(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment.');
        }

        $lockPath = $this->path.'.lock';
        $readyPath = $this->directory.'/lock-ready';
        $store = new FileClientToolCallStore($this->path);

        $script = <<<'PHP'
            $lock = fopen($argv[1], 'c');
            flock($lock, LOCK_EX);
            file_put_contents($argv[2], 'ready');
            usleep(400000);
            flock($lock, LOCK_UN);
            fclose($lock);
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, $lockPath, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process);

        try {
            $deadline = microtime(true) + 5.0;

            while (! is_file($readyPath) && microtime(true) < $deadline) {
                usleep(20000);
            }

            $this->assertFileExists($readyPath);

            $started = microtime(true);
            $begin = $store->begin($this->identity());
            $elapsed = microtime(true) - $started;

            $this->assertSame(ToolCallStatus::Started, $begin->status);
            $this->assertGreaterThanOrEqual(0.3, $elapsed);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function test_missing_state_file_is_treated_as_empty(): void
    {
        $store = new FileClientToolCallStore($this->path);

        $this->assertSame([], $store->records());
    }

    public function test_corrupt_state_file_throws_file_exception(): void
    {
        file_put_contents($this->path, 'not json at all');

        $this->expectException(FileException::class);

        (new FileClientToolCallStore($this->path))->begin($this->identity());
    }

    public function test_unsupported_format_throws_file_exception(): void
    {
        file_put_contents($this->path, json_encode(['format' => 99, 'calls' => []], JSON_THROW_ON_ERROR));

        $this->expectException(FileException::class);

        (new FileClientToolCallStore($this->path))->begin($this->identity());
    }

    public function test_missing_directory_throws_file_exception(): void
    {
        $this->expectException(FileException::class);

        new FileClientToolCallStore($this->directory.'/missing/calls.json');
    }

    public function test_state_file_never_contains_credentials_or_output_payloads(): void
    {
        (new FileClientToolCallStore($this->path))->begin($this->identity());

        $contents = (string) file_get_contents($this->path);

        $this->assertStringNotContainsString('api_key', $contents);
        $this->assertStringNotContainsString('secret', $contents);
        $this->assertStringNotContainsString('"output_hash": "', $contents);
    }

    public function test_marks_on_a_never_begun_call_throw(): void
    {
        $this->expectException(\LogicException::class);

        (new FileClientToolCallStore($this->path))->markResolved($this->identity());
    }

    public function test_empty_path_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileClientToolCallStore('');
    }
}
