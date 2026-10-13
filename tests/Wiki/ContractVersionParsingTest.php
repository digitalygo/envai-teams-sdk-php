<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Exceptions\ProtocolVersionException;
use Digitalygo\Envai\Models\Completion\CompletionTerminalSnapshot;
use Digitalygo\Envai\Models\System\Capabilities;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractVersionParsingTest extends TestCase
{
    public static function sample(string $type): array
    {
        $wire = Fixtures::sample($type);
        $convert = static function (mixed $node) use (&$convert): mixed {
            if (is_array($node)) {
                return array_map($convert, $node);
            }
            if (is_string($node) && preg_match('/^(evt|cht|cmpl|ag|call)_/', $node, $match)) {
                return $match[1].'_01ARZ3NDEKTSV4RRFFQ69G5FAV';
            }

            return $node;
        };

        return $convert($wire);
    }

    public static function costCases(): iterable
    {
        foreach (['turn.completed', 'turn.cancelled', 'turn.failed', 'agent.done', 'agent.tool.complete'] as $type) {
            foreach ([2, 3] as $version) {
                foreach ([0, 0.125, null] as $cost) {
                    yield $type.' v'.$version.' '.json_encode($cost) => [$type, $version, $cost];
                }
            }
        }
    }

    #[DataProvider('costCases')]
    public function test_every_cost_event_validates_its_own_version(string $type, int $version, int|float|null $cost): void
    {
        $wire = self::sample($type);
        $wire['v'] = $version;
        $wire['data']['usage']['cost_usd'] = $cost;
        if (str_starts_with($type, 'turn.')) {
            $wire['data']['cost'] = $cost;
            $wire['data']['chat_cost'] = $cost;
            $wire['data']['chat_usage']['cost_usd'] = $cost;
        }
        $event = (new AtpEventDecoder(3))->decode(json_encode($wire));
        if ($version === 2 && $cost === null) {
            self::assertInstanceOf(ProtocolViolationEvent::class, $event);
        } else {
            self::assertNotInstanceOf(ProtocolViolationEvent::class, $event);
            self::assertEquals($cost, $event->usage->costUsd);
            self::assertSame($wire, $event->raw());
            self::assertSame($version, $event->envelope->v);
        }
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/'.($version === 2 ? 'events-v2.json' : 'events.json')));
        $schema->{'$ref'} = '#/$defs/Usage';
        self::assertSame($version === 3 || $cost !== null, (new Validator)->validate(json_decode(json_encode($wire['data']['usage'])), $schema)->isValid());
    }

    public function test_v2_profile_rejects_v3_before_payload_interpretation(): void
    {
        $this->expectException(ProtocolVersionException::class);
        (new AtpEventDecoder(2))->decode('{"v":3,"data":"invalid"}');
    }

    public function test_unsupported_decoder_versions_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AtpEventDecoder(4);
    }

    public function test_missing_and_string_costs_are_never_accepted_as_unknown(): void
    {
        foreach (['turn.completed', 'turn.cancelled', 'turn.failed', 'agent.done', 'agent.tool.complete'] as $type) {
            foreach ([2, 3] as $version) {
                $wire = self::sample($type);
                $wire['v'] = $version;
                unset($wire['data']['usage']['cost_usd']);
                self::assertInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder)->decode(json_encode($wire)));
                $wire['data']['usage']['cost_usd'] = '0';
                self::assertInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder)->decode(json_encode($wire)));
            }
        }
    }

    public function test_snapshot_versions_and_capabilities_are_typed_and_preserved(): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/completion-terminal/completed.json'), true);
        $data['v'] = 3;
        $data['accepted_protocol_version'] = 2;
        $snapshot = CompletionTerminalSnapshot::fromArray($data);
        self::assertSame(3, $snapshot->v);
        self::assertSame(2, $snapshot->acceptedProtocolVersion);
        self::assertEquals($data, $snapshot->toArray());
        $capabilities = Capabilities::fromArray(ContractNegotiationTest::capabilities());
        self::assertSame(3, $capabilities->contractVersion);
        self::assertSame([2, 3], $capabilities->supportedContractVersions);
        self::assertSame('historical_reads_replay_only', $capabilities->legacyV2Mode);
        self::assertEquals(ContractNegotiationTest::capabilities(), $capabilities->toArray());
        foreach ([['v' => 1], ['accepted_protocol_version' => 4]] as $changes) {
            try {
                CompletionTerminalSnapshot::fromArray(array_replace($data, $changes));
                self::fail('Unknown snapshot version accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach (['3', ['3'], ['three' => 3]] as $invalid) {
            try {
                Capabilities::fromArray([...ContractNegotiationTest::capabilities(), 'supported_contract_versions' => $invalid]);
                self::fail('Malformed supported versions accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_each_terminal_cost_field_is_independently_version_checked(): void
    {
        foreach (['turn.completed', 'turn.cancelled', 'turn.failed'] as $type) {
            foreach (['cost', 'chat_cost', 'usage', 'chat_usage'] as $field) {
                foreach ([2, 3] as $version) {
                    foreach (['missing', 'string', 'negative', 'null'] as $case) {
                        $wire = self::sample($type);
                        $wire['v'] = $version;
                        $target = &$wire['data'];
                        $key = $field;
                        if (in_array($field, ['usage', 'chat_usage'], true)) {
                            $target = &$wire['data'][$field];
                            $key = 'cost_usd';
                        }
                        if ($case === 'missing') {
                            unset($target[$key]);
                        } else {
                            $target[$key] = match ($case) {
                                'string' => '0', 'negative' => -1, 'null' => null
                            };
                        }
                        unset($target);
                        $event = (new AtpEventDecoder)->decode(json_encode($wire));
                        self::assertSame($version !== 3 || $case !== 'null', $event instanceof ProtocolViolationEvent, $type.' '.$field.' v'.$version.' '.$case);
                    }
                }
            }
        }
    }

    public function test_current_usage_fields_preserve_required_numeric_constraints(): void
    {
        foreach (['turn.completed', 'agent.done', 'agent.tool.complete'] as $type) {
            foreach (['duration_ms', 'input_tokens', 'output_tokens', 'sprite_ms', 'sprite_ram_gb_ms', 'tool_calls'] as $key) {
                foreach ([null, -1, '0'] as $invalid) {
                    $wire = self::sample($type);
                    $wire['data']['usage'][$key] = $invalid;
                    self::assertInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder)->decode(json_encode($wire)));
                }
            }
            $wire = self::sample($type);
            unset($wire['data']['usage']['duration_ms']);
            self::assertInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder)->decode(json_encode($wire)));
        }
    }
}
