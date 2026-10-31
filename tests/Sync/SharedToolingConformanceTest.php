<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompilationResult;
use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\Planner\SyncOptions;
use Digitalygo\Envai\Sync\Planner\SyncPlanner;
use Digitalygo\Envai\Sync\Planner\SyncScope;
use Digitalygo\Envai\Sync\Reconciler\SyncReconciler;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;
use PHPUnit\Framework\TestCase;

final class SharedToolingConformanceTest extends TestCase
{
    public function test_frozen_hash_vectors_and_seventeen_planner_reconciler_cases(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/parity/tooling-golden.json'), true, flags: JSON_THROW_ON_ERROR);
        $hasher = new CanonicalManifestHasher;
        foreach ($fixture['hash_vectors'] as $vector) {
            self::assertSame($vector['sha256'], $hasher->resourceHash('tool', 'echo', $this->tool($vector['document']['tools']['echo']['description'])->toRemotePayload()));
        }
        foreach ($fixture['planner_reconciler_cases'] as $case) {
            $store = new InMemorySyncStateStore;
            if ($case['last_applied'] !== null) {
                $payload = $this->tool($case['last_applied']['description'])->toRemotePayload();
                $store->saveResource(new ManagedResourceState('audit', ResourceType::Tool, 'echo', remoteId: 'twl_echo', remoteName: 'echo', desiredHash: $hasher->resourceHash('tool', 'echo', $payload), lastAppliedPayload: $payload, remoteMarker: '2026-01-01T00:00:00Z', status: ManagedResourceStatus::Applied));
            }
            $tools = $case['desired'] === null ? [] : ['echo' => $this->tool($case['desired']['description'])];
            $draft = new DefinitionManifest('1', $tools, [], [], '');
            $manifest = new DefinitionManifest('1', $tools, [], [], $hasher->manifestHash($draft));
            $compiled = new DefinitionCompilationResult($manifest, [], true);
            $remote = $case['remote'] === null ? null : array_merge($this->tool($case['remote']['description'])->toRemotePayload(), ['id' => 'twl_echo', 'updated_at' => '2026-01-01T00:00:00Z']);
            $options = $case['options'];
            if (isset($options['scope'])) {
                $options['scope'] = SyncScope::from($options['scope']);
            }
            $plan = (new SyncPlanner(new FakeRemoteReader($remote === null ? [] : [$remote]), $store))->plan($compiled, new SyncOptions(...$options), 'audit');
            $writer = new FakeRemoteWriter;
            if ($remote !== null) {
                $writer->seed('tool:echo', $remote);
            }
            $report = (new SyncReconciler($writer, $store))->apply($plan, $manifest);
            $saved = $store->getResource('audit', ResourceType::Tool, 'echo');
            $actual = ['operations' => array_map(static fn ($op) => $op->toArray(), $plan->operations), 'mutation_calls' => $writer->calls, 'report_status' => $report->status->value, 'manifest_ready' => $report->manifestReady, 'outcomes' => array_map(static fn ($r) => $r->outcome->value, $report->results), 'stored' => $saved === null ? null : ['remote_id' => $saved->remoteId, 'remote_name' => $saved->remoteName, 'status' => $saved->status->value, 'last_applied' => $saved->lastAppliedPayload]];
            $expected = $case['expected'];
            if ($case['id'] === 'adopt_different') {
                // Normative safety-overrides.json supersedes this historic result.
                $expected['manifest_ready'] = false;
            }
            self::assertSame($expected, json_decode(json_encode($actual, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), $case['id']);
        }
    }

    private function tool(string $description): ToolDefinition
    {
        return new ToolDefinition('echo', FakeEchoTool::class, $description, new JsonObject(['type' => 'object', 'properties' => new JsonObject(['text' => new JsonObject(['type' => 'string'])])]), 30);
    }
}
