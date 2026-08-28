<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\File;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\File\FileSyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

/**
 * File-store-specific tests (project document section 29.5): atomic
 * rename with orphan recovery, truncated and corrupt files fail closed,
 * empty files are fresh stores, concurrent locking, 0600 permissions,
 * lossless format upgrades, absence of credentials and base URL
 * handling.
 */
final class FileSyncStateStoreTest extends SyncTestCase
{
    private string $directory = '';

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/atp-file-store-'.bin2hex(random_bytes(8));
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

    public function test_stale_temporary_file_is_ignored_and_cleaned_up(): void
    {
        $store = $this->store();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1'));

        // Simulate a crash between the temporary write and the rename.
        $orphan = $this->path.'.tmp.'.getmypid().'.crashed';
        file_put_contents($orphan, '{"format": 1, "connections": "garbage');

        $reopened = new FileSyncStateStore($this->path);

        // The last valid state file wins; the orphan temp is removed.
        $this->assertSame('twl_1', $reopened->getResource('production', ResourceType::Tool, 'a')?->remoteId);
        $this->assertFileDoesNotExist($orphan);
    }

    public function test_orphaned_temporary_file_alone_yields_a_fresh_store(): void
    {
        file_put_contents($this->path.'.tmp.'.getmypid().'.crashed', '{"format": 1, "connections": "garbage');

        $store = new FileSyncStateStore($this->path);

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'a'));

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1'));

        $this->assertSame('twl_1', $store->getResource('production', ResourceType::Tool, 'a')?->remoteId);
    }

    public function test_corrupt_json_fails_closed(): void
    {
        file_put_contents($this->path, '{not valid json at all');

        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('envai state file is corrupt');
        $this->expectExceptionMessage($this->path);

        $store->getResource('production', ResourceType::Tool, 'a');
    }

    public function test_truncated_json_fails_closed(): void
    {
        file_put_contents($this->path, '{"format": 1, "connections": {"production');

        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('envai state file is corrupt');

        // resources() is a generator: force execution by iterating it.
        iterator_to_array($store->resources('production'));
    }

    public function test_unknown_format_fails_closed(): void
    {
        file_put_contents($this->path, '{"format": 2, "connections": {}}');

        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('envai state file is corrupt');

        $store->readyManifest('production');
    }

    public function test_empty_file_is_treated_as_fresh_store(): void
    {
        file_put_contents($this->path, '');

        $store = $this->store();

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'a'));
        $this->assertNull($store->readyManifest('production'));

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1'));

        $this->assertSame('twl_1', $store->getResource('production', ResourceType::Tool, 'a')?->remoteId);
    }

    public function test_whitespace_only_file_is_treated_as_fresh_store(): void
    {
        file_put_contents($this->path, "  \n\t  ");

        $store = $this->store();

        $this->assertNull($store->getResource('production', ResourceType::Tool, 'a'));

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1'));

        $this->assertSame('twl_1', $store->getResource('production', ResourceType::Tool, 'a')?->remoteId);
    }

    public function test_lock_is_exclusive_across_processes(): void
    {
        if (! function_exists('proc_open') || PHP_BINARY === '') {
            $this->markTestSkipped('proc_open or PHP_BINARY is unavailable; skipping the cross-process lock test.');
        }

        $store = $this->store();
        $lock = $store->acquireLock('production');

        $this->assertSame('busy', $this->probeLockFromChildProcess());

        $lock->release();

        $this->assertSame('locked', $this->probeLockFromChildProcess());
    }

    public function test_lock_excludes_a_second_store_instance(): void
    {
        $first = new FileSyncStateStore($this->path, lockTimeoutSeconds: 0.3);
        $second = new FileSyncStateStore($this->path, lockTimeoutSeconds: 0.3);

        $lock = $first->acquireLock('production');
        $this->assertFalse($lock->isReleased());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not acquire envai state lock');

        $second->acquireLock('production');
    }

    public function test_state_file_permissions_are_0600(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('chmod semantics differ on Windows; permissions are asserted on POSIX platforms.');
        }

        $store = $this->store();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a'));

        $this->assertSame(0600, fileperms($this->path) & 0777);

        $store->setBaseUrl('production', 'https://api.envai.example');

        $this->assertSame(0600, fileperms($this->path) & 0777);
    }

    public function test_format_upgrade_preserves_unknown_fields(): void
    {
        $document = [
            'format' => 1,
            'schema_note' => 'written by an older tool',
            'connections' => [
                'production' => [
                    'base_url' => 'https://api.envai.example',
                    'ready_manifest' => null,
                    'future_flag' => ['enabled' => true, 'nested' => (object) []],
                    'resources' => [
                        'tool:legacy' => [
                            'remote_id' => 'twl_legacy',
                            'future_resource_field' => ['keep' => 'me'],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($this->path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $store = $this->store();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'new', remoteId: 'twl_new'));
        $store->markManifestReady('production', 'sha256:manifest');

        $decoded = json_decode((string) file_get_contents($this->path), false, 512, JSON_THROW_ON_ERROR);

        // Unknown fields survive the rewrite, including empty objects.
        $this->assertSame('written by an older tool', $decoded->schema_note);
        $this->assertTrue($decoded->connections->production->future_flag->enabled);
        $this->assertInstanceOf(\stdClass::class, $decoded->connections->production->future_flag->nested);
        $this->assertSame('me', $decoded->connections->production->resources->{'tool:legacy'}->future_resource_field->keep);
        $this->assertSame('twl_legacy', $decoded->connections->production->resources->{'tool:legacy'}->remote_id);
        $this->assertObjectHasProperty('tool:new', $decoded->connections->production->resources);
        $this->assertSame('sha256:manifest', $decoded->connections->production->ready_manifest);
    }

    public function test_serialized_content_contains_no_credentials(): void
    {
        $store = $this->store();
        $store->setBaseUrl('production', 'https://api.envai.example');
        $store->saveResource(new ManagedResourceState(
            connection: 'production',
            type: ResourceType::Agent,
            localKey: 'orchestrator',
            remoteId: 'agt_1',
            remoteName: 'orchestrator',
            desiredHash: 'sha256:abc',
            lastAppliedPayload: new JsonObject(['name' => 'orchestrator', 'system_prompt' => 'You are an agent.']),
            remoteMarker: '2026-08-28T10:00:00Z',
            synchronizedAt: new \DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            status: ManagedResourceStatus::Applied,
        ));

        $raw = (string) file_get_contents($this->path);

        $this->assertStringNotContainsString('sk_', $raw);
        $this->assertStringNotContainsString('api_key', $raw);
        $this->assertStringNotContainsString('authorization', $raw);
        $this->assertStringNotContainsString('secret', $raw);
        $this->assertSame('https://api.envai.example', $store->baseUrl('production'));
    }

    public function test_base_url_roundtrip_across_instances(): void
    {
        $store = $this->store();

        $this->assertNull($store->baseUrl('production'));

        $store->setBaseUrl('production', 'https://api.envai.example');

        $this->assertSame('https://api.envai.example', $store->baseUrl('production'));

        $reopened = new FileSyncStateStore($this->path);

        $this->assertSame('https://api.envai.example', $reopened->baseUrl('production'));

        $store->setBaseUrl('production', null);

        $this->assertNull($store->baseUrl('production'));
    }

    public function test_set_base_url_rejects_credentials_and_non_origin_urls(): void
    {
        $store = $this->store();

        foreach ([
            'https://user:secret@api.envai.example',
            'https://api.envai.example?token=abc',
            'https://api.envai.example#fragment',
            'ftp://api.envai.example',
            'not a url',
        ] as $invalid) {
            try {
                $store->setBaseUrl('production', $invalid);
                $this->fail('Expected an InvalidArgumentException for base_url "'.$invalid.'".');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($store->baseUrl('production'));
    }

    public function test_updated_at_is_written_and_refreshed(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $holder = $this->clockHolder($now);
        $store = new FileSyncStateStore($this->path, nowProvider: fn (): \DateTimeImmutable => $holder->now);

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1'));

        $this->assertSame('2026-08-28T10:00:00.000000+00:00', $this->resourceUpdatedAt('production', 'tool:a'));

        $holder->now = $holder->now->modify('+1 hour');
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_2'));

        $this->assertSame('2026-08-28T11:00:00.000000+00:00', $this->resourceUpdatedAt('production', 'tool:a'));
    }

    private function store(float $lockTimeoutSeconds = 10.0): FileSyncStateStore
    {
        return new FileSyncStateStore($this->path, lockTimeoutSeconds: $lockTimeoutSeconds);
    }

    private function probeLockFromChildProcess(): string
    {
        $script = '$h = fopen($argv[1], "c"); $ok = flock($h, LOCK_EX | LOCK_NB); echo $ok ? "locked" : "busy";';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $this->path.'.lock'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('', trim((string) $stderr), 'The lock probe emitted stderr.');

        return trim((string) $stdout);
    }

    private function resourceUpdatedAt(string $connection, string $resourceKey): string
    {
        $decoded = json_decode((string) file_get_contents($this->path), false, 512, JSON_THROW_ON_ERROR);

        return $decoded->connections->{$connection}->resources->{$resourceKey}->updated_at;
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

    /**
     * @return object{now: \DateTimeImmutable}
     */
    private function clockHolder(\DateTimeImmutable $now): object
    {
        return new class($now)
        {
            public \DateTimeImmutable $now;

            public function __construct(\DateTimeImmutable $now)
            {
                $this->now = $now;
            }
        };
    }
}
