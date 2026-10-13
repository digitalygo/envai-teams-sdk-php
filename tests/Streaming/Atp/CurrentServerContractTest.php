<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Exceptions\ProtocolVersionException;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\AgentContextCompactedEvent;
use Digitalygo\Envai\Streaming\Events\AgentContextCompactingEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\UnknownEvent;
use Digitalygo\Envai\Streaming\Sse\SseParser;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use PHPUnit\Framework\TestCase;

final class CurrentServerContractTest extends TestCase
{
    private static function fixture(string $name): mixed
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/current-server/'.$name), false, 512, JSON_THROW_ON_ERROR);
    }

    public function test_all_declared_server_sources_are_byte_exact(): void
    {
        $provenance = self::fixture('provenance.json');
        $this->assertSame('digitalygo/envai-teams', $provenance->repository);
        $this->assertSame('5bfc6dd3c82af6afc04e7cc1f3323906cfd4f0e2', $provenance->commit);
        $this->assertSame('b590e6b8758b53346d4e0356c384585373b1b8ea', $provenance->base_commit);
        $this->assertSame('pinned backend commit', $provenance->snapshot_status);
        $this->assertSame(['openapi.yaml', 'events.json', 'wiki.json', 'terminal-snapshot.json'], array_keys(get_object_vars($provenance->sources)));
        foreach ($provenance->sources as $name => $source) {
            $bytes = file_get_contents(dirname(__DIR__, 3).'/'.$source->snapshot_path);
            $this->assertSame($source->bytes, strlen($bytes), $name.' byte count');
            $this->assertSame($source->sha256, hash('sha256', $bytes), $name.' SHA-256');
            $this->assertSame($source->git_blob_sha, sha1('blob '.strlen($bytes)."\0".$bytes), $name.' pinned Git blob');
            $this->assertStringEndsWith('/'.$name, $source->repository_path);
        }
    }

    public function test_generated_fixtures_are_separate_and_all_hashes_are_checked(): void
    {
        $provenance = self::fixture('provenance.json');
        $fixtures = get_object_vars($provenance->generated_fixtures);
        $this->assertCount(7, $fixtures);
        foreach ($fixtures as $name => $fixture) {
            $this->assertSame('tests/fixtures/current-server/'.$name, $fixture->snapshot_path);
            $this->assertNotEmpty($fixture->origin);
            $this->assertSame($fixture->sha256, hash_file('sha256', dirname(__DIR__, 3).'/'.$fixture->snapshot_path), $name);
        }
    }

    public function test_shared_current_server_events_and_unknown_extension_decode(): void
    {
        $events = self::fixture('events-valid.json');
        foreach ($events as $wire) {
            $event = (new AtpEventDecoder)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
            $this->assertNotInstanceOf(ProtocolViolationEvent::class, $event, $wire->type);
            $this->assertSame($wire->type, $event->envelope->type);
        }
        $this->assertInstanceOf(UnknownEvent::class, $event);
        $this->assertSame('preserved', $event->data->get('new'));
    }

    public function test_shared_invalid_version_sequence_and_tool_kind_are_rejected(): void
    {
        foreach (self::fixture('events-invalid.json') as $case) {
            if (! in_array($case->name, ['unsupported-version', 'replay-seq-zero', 'wrong-tool-kind', 'missing-turn'], true)) {
                continue; // Identifier-shape validation is intentionally not part of the legacy decoder.
            }
            try {
                $event = (new AtpEventDecoder)->decode(json_encode($case->event, JSON_THROW_ON_ERROR));
                $this->assertInstanceOf(ProtocolViolationEvent::class, $event, $case->name);
            } catch (ProtocolVersionException) {
                $this->assertSame('unsupported-version', $case->name);
            }
        }
    }

    public function test_shared_signature_vectors_verify_exact_canonical_bytes(): void
    {
        $fixture = self::fixture('signatures.json');
        $resolver = new JwksResolver(static fn (): string => json_encode($fixture->jwks, JSON_THROW_ON_ERROR), 'https://envai.invalid/.well-known/jwks.json');
        $verifier = new ToolRequestVerifier($resolver);
        $events = self::fixture('events-valid.json');
        foreach ($fixture->vectors as $vector) {
            $wire = clone $events[4];
            $wire->data = (object) [
                'kind' => 'client',
                'call_id' => $vector->call_id,
                'tool_name' => $vector->tool_name,
                'args' => json_decode($vector->args_json, false, 512, JSON_THROW_ON_ERROR),
                'signature' => $vector->signature,
            ];
            $event = (new AtpEventDecoder)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
            $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
            $this->assertSame($vector->canonical, CanonicalJsonSerializer::serialize($event->args));
            $this->assertSame($vector->call_id, $verifier->verify($event)->callId);
        }
    }

    public function test_shared_sse_cases_at_every_byte_split(): void
    {
        foreach (self::fixture('sse-cases.json') as $case) {
            for ($split = 0; $split <= strlen($case->wire); $split++) {
                $parser = new SseParser;
                $data = [];
                foreach ([substr($case->wire, 0, $split), substr($case->wire, $split)] as $chunk) {
                    $parser->feed($chunk);
                    while (($frame = $parser->nextFrame()) !== null) {
                        $data[] = $frame->data;
                    }
                }
                $this->assertSame($case->data, $data, $case->name.' at '.$split);
            }
        }
    }

    public function test_optional_compaction_and_usage_extensions_are_typed(): void
    {
        $wire = Fixtures::sample('agent.context.compacting');
        $wire['data'] = ['turn_id' => Fixtures::TURN_ID, 'reason' => 'tokens', 'context_tokens' => 180000, 'threshold_tokens' => 160000, 'window_tokens' => 200000, 'threshold' => 0.8, 'future' => true];
        $event = (new AtpEventDecoder)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(AgentContextCompactingEvent::class, $event);
        $this->assertSame(180000, $event->contextTokens);
        $this->assertSame(0.8, $event->threshold);
        $this->assertSame(['future' => true], $event->extra);
        $wire['type'] = 'agent.context.compacted';
        $wire['data'] = ['new_context' => 'summary', 'turn_id' => Fixtures::TURN_ID, 'context_tokens' => 180000];
        $event = (new AtpEventDecoder)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(AgentContextCompactedEvent::class, $event);
        $this->assertSame(Fixtures::TURN_ID, $event->turnId);
        $this->assertSame(180000, $event->contextTokens);

        $wire = Fixtures::sample('turn.completed');
        $wire['data']['usage']['sprite_ram_gb_ms'] = 1234;
        $wire['data']['usage']['tool_calls'] = 2;
        $event = (new AtpEventDecoder)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(TurnCompletedEvent::class, $event);
        $this->assertSame(1234, $event->usage->spriteRamGbMs);
        $this->assertSame(2, $event->usage->toolCalls);
    }
}
