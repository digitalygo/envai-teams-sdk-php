<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\TestCase;

final class WikiUsageCompatibilityTest extends TestCase
{
    public function test_terminal_unknown_costs_are_null_and_new_usage_is_typed(): void
    {
        foreach (['turn.completed', 'turn.failed', 'turn.cancelled'] as $type) {
            $wire = Fixtures::sample($type);
            $wire['data']['cost'] = null;
            $wire['data']['chat_cost'] = null;
            $wire['data']['usage']['cost_usd'] = null;
            $wire['data']['usage']['billing'] = ['status' => 'inactive'];
            $wire['data']['usage']['usage_cost'] = WikiContractTest::fixture('usage-pending')['data'];
            $wire['data']['billing'] = ['status' => 'inactive'];
            $wire['data']['usage_cost'] = WikiContractTest::fixture('usage-pending')['data'];
            $event = (new AtpEventDecoder)->decode(json_encode($wire));
            self::assertNotInstanceOf(ProtocolViolationEvent::class, $event);
            self::assertNull($event->cost);
            self::assertNull($event->chatCost);
            self::assertNull($event->usage->costUsd);
            self::assertSame('pending', $event->usage->usageCost->status);
            self::assertSame('inactive', $event->billing->status);
            self::assertSame('0.01234000', $event->usageCost->pricedSubtotals[0]->amount);
            self::assertSame($wire, $event->raw());
        }
    }

    public function test_new_usage_invalid_values_surface_protocol_violations(): void
    {
        foreach (['billing' => ['status' => 'charged'], 'usage_cost' => ['status' => 'complete']] as $field => $value) {
            foreach (['usage', 'terminal'] as $location) {
                $wire = Fixtures::sample('turn.completed');
                if ($location === 'usage') {
                    $wire['data']['usage'][$field] = $value;
                } else {
                    $wire['data'][$field] = $value;
                }
                self::assertInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder)->decode(json_encode($wire)));
            }
        }
    }

    public function test_completion_unknown_cost_and_optional_prebilling_models_roundtrip(): void
    {
        $base = [
            'id' => 'completion', 'workspace_id' => 'workspace', 'chat_id' => 'chat', 'team_id' => 'team', 'user_id' => 'owner',
            'model_orchestrator' => null, 'models_subagents' => [], 'started_at' => '2026-10-13T16:00:00Z',
            'usage' => ['prompt_tokens' => 1], 'cost_usd' => null, 'status' => 'succeeded', 'finish_reason' => 'stop',
            'created_at' => '2026-10-13T16:00:00Z', 'updated_at' => '2026-10-13T16:00:00Z',
            'billing' => ['status' => 'inactive'], 'usage_cost' => WikiContractTest::fixture('usage-pending')['data'],
            'future' => 'kept',
        ];
        $model = Completion::fromArray($base);
        self::assertNull($model->costUsd);
        self::assertSame($base['usage_cost'], $model->usageCost->toArray());
        self::assertSame(['status' => 'inactive'], $model->toArray()['billing']);
        self::assertSame('kept', $model->extra['future']);
        unset($base['cost_usd'], $base['billing'], $base['usage_cost']);
        $legacy = Completion::fromArray($base);
        self::assertNull($legacy->costUsd);
        self::assertNull($legacy->usageCost);
        self::assertArrayNotHasKey('billing', $legacy->toArray());
    }
}
