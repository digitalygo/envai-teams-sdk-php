<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Models\Completion\CompletionTerminalSnapshot;
use Digitalygo\Envai\Tests\Client\FakeTransport;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class CompletionTerminalSnapshotTest extends TestCase
{
    private function snapshot(string $name = 'completed'): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/completion-terminal/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function completion(): array
    {
        return ['id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz', 'workspace_id' => 'workspace', 'chat_id' => 'chat', 'team_id' => 'team', 'user_id' => 'owner', 'models_subagents' => [], 'model_orchestrator' => null, 'started_at' => '2026-10-13T16:00:00Z', 'usage' => ['input_tokens' => 10], 'cost_usd' => null, 'status' => 'succeeded', 'created_at' => '2026-10-13T16:00:00Z', 'updated_at' => '2026-10-13T16:00:00Z'];
    }

    public function test_missing_and_null_legacy_snapshots_are_supported(): void
    {
        $legacy = Completion::fromArray($this->completion());
        self::assertNull($legacy->terminalSnapshot);
        self::assertArrayNotHasKey('terminal_snapshot', $legacy->toArray());
        $null = Completion::fromArray([...$this->completion(), 'terminal_snapshot' => null]);
        self::assertNull($null->terminalSnapshot);
        self::assertArrayNotHasKey('terminal_snapshot', $null->extra);
    }

    public function test_shared_completed_and_recovered_metadata_fixtures_validate_and_roundtrip(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/terminal-snapshot.json'));
        foreach (['completed', 'recovered'] as $name) {
            $wire = $this->snapshot($name);
            self::assertTrue((new Validator)->validate(json_decode(json_encode($wire)), $schema)->isValid());
            $completion = Completion::fromArray([...$this->completion(), 'terminal_snapshot' => $wire]);
            self::assertEquals($wire, $completion->terminalSnapshot->toArray());
            self::assertSame($wire['event_id'], $completion->terminalSnapshot->eventId);
            self::assertSame($wire['event_type'], $completion->terminalSnapshot->eventType);
            self::assertEquals($wire, $completion->toArray()['terminal_snapshot']);
            self::assertNull($completion->terminalSnapshot->data->get('chat_cost'));
            self::assertSame($wire['data']['chat_usage'], $completion->terminalSnapshot->data->get('chat_usage'));
        }
        $wire['data']['text'] = 'must not be a response replay';
        self::assertFalse((new Validator)->validate(json_decode(json_encode($wire)), $schema)->isValid());
    }

    public function test_chat_usage_matches_atp_fields_with_optional_legacy_snapshots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/terminal-snapshot.json'));
        $events = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/events.json'));
        $chatUsage = $schema->{'$defs'}->CompletionTerminalSnapshotChatUsage;
        self::assertEquals($events->{'$defs'}->Usage->properties, $chatUsage->properties);
        self::assertEquals($events->{'$defs'}->Usage->required, $chatUsage->required);
        $wire = $this->snapshot('recovered');
        unset($wire['data']['chat_usage'], $wire['data']['chat_cost']);
        self::assertTrue((new Validator)->validate(json_decode(json_encode($wire)), $schema)->isValid());
        $wire = $this->snapshot();
        $wire['data']['chat_cost'] = 1.25;
        $wire['data']['chat_usage']['cost_usd'] = 1.25;
        self::assertTrue((new Validator)->validate(json_decode(json_encode($wire)), $schema)->isValid());
        self::assertEquals($wire, CompletionTerminalSnapshot::fromArray($wire)->toArray());
        foreach ([['chat_cost' => '1.25'], ['chat_usage' => null], ['chat_usage' => ['cost_usd' => null]], ['chat_usage' => [...$wire['data']['chat_usage'], 'text' => 'not response replay']]] as $change) {
            $invalid = $wire;
            $invalid['data'] = array_replace($wire['data'], $change);
            self::assertFalse((new Validator)->validate(json_decode(json_encode($invalid)), $schema)->isValid());
        }
    }

    public function test_actual_normal_and_recovered_journal_payloads_validate_and_roundtrip(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/terminal-snapshot.json'));
        $provenance = $this->snapshot('provenance');
        self::assertStringContainsString('synthetic providers', $provenance['origin']);
        foreach ($provenance['files'] as $filename => $metadata) {
            $bytes = file_get_contents(dirname(__DIR__).'/fixtures/completion-terminal/'.$filename);
            self::assertSame($metadata['bytes'], strlen($bytes));
            self::assertSame($metadata['sha256'], hash('sha256', $bytes));
            self::assertTrue((new Validator)->validate(json_decode($bytes), $schema)->isValid());
            $wire = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $completion = Completion::fromArray([...$this->completion(), 'id' => $wire['data']['turn_id'], 'terminal_snapshot' => $wire]);
            self::assertEquals($wire, $completion->terminalSnapshot->toArray());
            self::assertSame($wire['data']['chat_usage']['cost_usd'], $wire['data']['chat_cost']);
            self::assertSame('inactive', $wire['data']['usage']['billing']['status']);
        }
    }

    public function test_current_usage_can_reconcile_without_mutating_the_terminal_snapshot(): void
    {
        $wire = [...$this->completion(), 'terminal_snapshot' => $this->snapshot(), 'billing' => ['status' => 'inactive'], 'usage_cost' => WikiContractTest::fixture('usage-pending')['data']];
        $initial = Completion::fromArray($wire);
        $wire['usage_cost']['status'] = 'complete';
        $wire['usage_cost']['basis'] = 'reported';
        $wire['usage_cost']['pending_invocation_count'] = 0;
        $wire['usage_cost']['revision']++;
        $wire['usage_cost']['totals'] = $wire['usage_cost']['priced_subtotals'];
        $wire['cost_usd'] = 0.01234;
        $latest = Completion::fromArray($wire);
        self::assertNull($initial->costUsd);
        self::assertSame(0.01234, $latest->costUsd);
        self::assertGreaterThan($initial->usageCost->revision, $latest->usageCost->revision);
        self::assertEquals($initial->terminalSnapshot->toArray(), $latest->terminalSnapshot->toArray());
        self::assertNull($latest->terminalSnapshot->data->get('cost'));
    }

    public function test_snapshot_turn_identity_must_match_its_scoped_completion(): void
    {
        $wire = $this->snapshot();
        $wire['data']['turn_id'] = 'different_completion';
        $this->expectException(\InvalidArgumentException::class);
        Completion::fromArray([...$this->completion(), 'terminal_snapshot' => $wire]);
    }

    public function test_metadata_get_keeps_workspace_chat_and_completion_scopes(): void
    {
        $transport = new FakeTransport;
        $wire = [...$this->completion(), 'terminal_snapshot' => $this->snapshot()];
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $wire])));
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('test')), $transport);
        $result = $client->completions()->get('workspace/a', 'chat/b', $wire['id']);
        self::assertStringContainsString('/workspaces/workspace%2Fa/chats/chat%2Fb/completions/'.$wire['id'], $transport->requests[0]->uri);
        self::assertSame('owner', $result->userId);
        self::assertNotNull($result->terminalSnapshot);
    }

    public function test_terminal_model_rejects_nonterminal_or_missing_turn_identity(): void
    {
        foreach ([['event_type' => 'agent.text.done'], ['event_id' => ''], ['data' => []]] as $changes) {
            try {
                CompletionTerminalSnapshot::fromArray(array_replace($this->snapshot(), $changes));
                self::fail('Invalid terminal metadata accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $wire = [...$this->snapshot(), 'future_metadata' => true];
        self::assertTrue(CompletionTerminalSnapshot::fromArray($wire)->extra['future_metadata']);
    }
}
