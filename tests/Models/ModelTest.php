<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Model\LlmModelEntry;
use Digitalygo\Envai\Models\Model\ModelDefaults;
use Digitalygo\Envai\Models\Model\ModelList;
use Digitalygo\Envai\Models\Model\ModelListEntry;
use Digitalygo\Envai\Models\Model\TeamModelEntry;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function test_model_list_hydrates_mixed_entries_by_discriminator(): void
    {
        $list = ModelList::fromArray([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'anthropic/claude-sonnet-4-5',
                    'object' => 'model',
                    'created' => 1759190400,
                    'owned_by' => 'anthropic',
                    'type' => 'llm',
                    'deprecated' => false,
                    'sunset_at' => '2027-05-21T00:00:00+00:00',
                ],
                [
                    'id' => 'openai/gpt-5-mini',
                    'object' => 'model',
                    'created' => 1759190400,
                    'owned_by' => 'openai',
                    'type' => 'llm',
                    'deprecated' => true,
                    'sunset_at' => null,
                ],
                [
                    'id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
                    'object' => 'model',
                    'type' => 'team',
                    'owned_by' => 'envai',
                    'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
                    'created' => 1759190400,
                    'deprecated' => false,
                    'sunset_at' => null,
                ],
            ],
            'defaults' => [
                'orchestrator' => 'anthropic/claude-sonnet-4-5',
                'subagent' => 'openai/gpt-5-mini',
            ],
            'api_revision' => 4,
        ]);

        $this->assertSame('list', $list->object);
        $this->assertCount(3, $list->data);

        $this->assertInstanceOf(LlmModelEntry::class, $list->data[0]);
        $this->assertInstanceOf(LlmModelEntry::class, $list->data[1]);
        $this->assertInstanceOf(TeamModelEntry::class, $list->data[2]);

        $this->assertSame('anthropic', $list->data[0]->ownedBy);
        $this->assertTrue($list->data[1]->deprecated);
        $this->assertNull($list->data[1]->sunsetAt);
        $this->assertSame('team_01j5abcdefghjkmnpqrstvwxyz', $list->data[2]->teamId);

        $this->assertSame('anthropic/claude-sonnet-4-5', $list->defaults->orchestrator);
        $this->assertSame('openai/gpt-5-mini', $list->defaults->subagent);
        $this->assertSame(['api_revision' => 4], $list->extra);
    }

    public function test_model_list_entry_interface_exposes_common_surface(): void
    {
        $list = ModelList::fromArray([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'anthropic/claude-sonnet-4-5',
                    'object' => 'model',
                    'created' => 1759190400,
                    'owned_by' => 'anthropic',
                    'type' => 'llm',
                    'deprecated' => false,
                    'sunset_at' => null,
                ],
                [
                    'id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
                    'object' => 'model',
                    'type' => 'team',
                    'owned_by' => 'envai',
                    'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
                    'deprecated' => false,
                    'sunset_at' => null,
                ],
            ],
            'defaults' => ['orchestrator' => 'a', 'subagent' => 'b'],
        ]);

        foreach ($list->data as $entry) {
            $this->assertInstanceOf(ModelListEntry::class, $entry);
            $this->assertSame('model', $entry->object());
            $this->assertFalse($entry->deprecated());
            $this->assertNull($entry->sunsetAt());
            $this->assertNotEmpty($entry->id());
            $this->assertNotEmpty($entry->type());
            $this->assertNotEmpty($entry->ownedBy());
        }
    }

    public function test_model_list_throws_on_unknown_discriminator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has unknown discriminator "type" value');

        ModelList::fromArray([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'gateway/edge',
                    'object' => 'model',
                    'type' => 'gateway',
                ],
            ],
            'defaults' => ['orchestrator' => 'a', 'subagent' => 'b'],
        ]);
    }

    public function test_team_model_entry_accepts_absent_created(): void
    {
        $entry = TeamModelEntry::fromArray([
            'id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'object' => 'model',
            'type' => 'team',
            'owned_by' => 'envai',
            'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'deprecated' => false,
            'sunset_at' => null,
        ]);

        $this->assertNull($entry->created);
        $this->assertSame('team_01j5abcdefghjkmnpqrstvwxyz', $entry->id());
    }

    public function test_llm_model_entry_preserves_extra(): void
    {
        $entry = LlmModelEntry::fromArray([
            'id' => 'anthropic/claude-sonnet-4-5',
            'object' => 'model',
            'created' => 1759190400,
            'owned_by' => 'anthropic',
            'type' => 'llm',
            'deprecated' => false,
            'sunset_at' => null,
            'context_window' => 200000,
        ]);

        $this->assertSame(['context_window' => 200000], $entry->extra);
    }

    public function test_model_list_round_trips_entries_and_defaults(): void
    {
        $payload = [
            'object' => 'list',
            'data' => [
                [
                    'id' => 'anthropic/claude-sonnet-4-5',
                    'object' => 'model',
                    'created' => 1759190400,
                    'owned_by' => 'anthropic',
                    'type' => 'llm',
                    'deprecated' => false,
                    'sunset_at' => null,
                ],
                [
                    'id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
                    'object' => 'model',
                    'type' => 'team',
                    'owned_by' => 'envai',
                    'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
                    'created' => 1759190400,
                    'deprecated' => false,
                    'sunset_at' => null,
                ],
            ],
            'defaults' => [
                'orchestrator' => 'anthropic/claude-sonnet-4-5',
                'subagent' => 'openai/gpt-5-mini',
            ],
        ];

        $this->assertEquals($payload, ModelList::fromArray($payload)->toArray());
    }

    public function test_model_defaults_hydrates_and_preserves_extra(): void
    {
        $defaults = ModelDefaults::fromArray([
            'orchestrator' => 'anthropic/claude-sonnet-4-5',
            'subagent' => 'openai/gpt-5-mini',
            'fallback' => 'anthropic/claude-haiku-4-5',
        ]);

        $this->assertSame('openai/gpt-5-mini', $defaults->subagent);
        $this->assertSame(['fallback' => 'anthropic/claude-haiku-4-5'], $defaults->extra);
    }
}
