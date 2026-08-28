<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\Tool\UserTool;
use Digitalygo\Envai\Models\Tool\UserToolCreateRequest;
use Digitalygo\Envai\Models\Tool\UserToolUpdateRequest;
use PHPUnit\Framework\TestCase;

final class ToolTest extends TestCase
{
    public function test_user_tool_hydrates_from_realistic_payload_and_preserves_extra(): void
    {
        $tool = UserTool::fromArray([
            'id' => 'twl_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'lookup_order',
            'kind' => 'client',
            'description' => 'Look up an order by id',
            'parameters' => [
                'type' => 'object',
                'properties' => ['order_id' => ['type' => 'string']],
                'required' => ['order_id'],
            ],
            'timeout_seconds' => 120,
            'created_at' => '2026-08-17T14:00:00Z',
            'updated_at' => '2026-08-17T14:00:00Z',
            'owner_scope' => 'user',
        ]);

        $this->assertSame('twl_01j5abcdefghjkmnpqrstvwxyz', $tool->id);
        $this->assertSame('lookup_order', $tool->name);
        $this->assertSame('client', $tool->kind);
        $this->assertSame('Look up an order by id', $tool->description);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['order_id' => ['type' => 'string']],
            'required' => ['order_id'],
        ], $tool->parameters);
        $this->assertSame(120, $tool->timeoutSeconds);
        $this->assertSame(['owner_scope' => 'user'], $tool->extra);
    }

    public function test_user_tool_preserves_unknown_nested_keys_in_parameters(): void
    {
        $tool = UserTool::fromArray([
            'id' => 'twl_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'send_email',
            'kind' => 'client',
            'description' => null,
            'parameters' => [
                'type' => 'object',
                'properties' => ['to' => ['type' => 'string', 'x-docs-note' => 'future annotation']],
                'required' => ['to'],
            ],
            'timeout_seconds' => 120,
            'created_at' => '2026-08-17T14:00:00Z',
            'updated_at' => '2026-08-17T14:00:00Z',
        ]);

        $this->assertNull($tool->description);
        $this->assertSame('future annotation', $tool->parameters['properties']['to']['x-docs-note']);
        $this->assertSame(['to'], $tool->parameters['required']);
    }

    public function test_user_tool_throws_on_missing_required_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UserTool payload is missing required field "name"');

        UserTool::fromArray([
            'id' => 'twl_01j5abcdefghjkmnpqrstvwxyz',
            'kind' => 'client',
            'description' => null,
            'parameters' => ['type' => 'object'],
            'timeout_seconds' => 120,
            'created_at' => '2026-08-17T14:00:00Z',
            'updated_at' => '2026-08-17T14:00:00Z',
        ]);
    }

    public function test_user_tool_round_trips_through_to_array(): void
    {
        $payload = [
            'id' => 'twl_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'lookup_order',
            'kind' => 'client',
            'description' => 'Look up an order by id',
            'parameters' => ['type' => 'object'],
            'timeout_seconds' => 120,
            'created_at' => '2026-08-17T14:00:00Z',
            'updated_at' => '2026-08-17T14:00:00Z',
        ];

        $this->assertEquals($payload, UserTool::fromArray($payload)->toArray());
    }

    public function test_user_tool_create_request_serializes_defaults(): void
    {
        $request = new UserToolCreateRequest(
            name: 'send_email',
            parameters: ['type' => 'object'],
        );

        $this->assertSame([
            'name' => 'send_email',
            'kind' => 'client',
            'parameters' => ['type' => 'object'],
            'timeout_seconds' => 120,
        ], $request->toArray());
    }

    public function test_user_tool_create_request_serializes_optional_fields(): void
    {
        $request = new UserToolCreateRequest(
            name: 'send_email',
            parameters: ['type' => 'object'],
            description: 'Send an email through the client SMTP server',
            timeoutSeconds: 300,
        );

        $this->assertSame([
            'name' => 'send_email',
            'kind' => 'client',
            'parameters' => ['type' => 'object'],
            'timeout_seconds' => 300,
            'description' => 'Send an email through the client SMTP server',
        ], $request->toArray());
    }

    public function test_user_tool_update_request_serializes_only_present_fields(): void
    {
        $request = new UserToolUpdateRequest(
            description: OptionalField::of(null),
            parameters: OptionalField::of(['type' => 'object']),
            timeoutSeconds: OptionalField::of(600),
        );

        $this->assertSame([
            'description' => null,
            'parameters' => ['type' => 'object'],
            'timeout_seconds' => 600,
        ], $request->toArray());
    }

    public function test_user_tool_update_request_distinguishes_absent_from_null_description(): void
    {
        $clearing = UserToolUpdateRequest::description(null);
        $untouched = new UserToolUpdateRequest(
            description: OptionalField::absent(),
            parameters: OptionalField::absent(),
            timeoutSeconds: OptionalField::absent(),
        );

        $this->assertSame(['description' => null], $clearing->toArray());
        $this->assertSame([], $untouched->toArray());
    }

    public function test_user_tool_update_request_static_factories(): void
    {
        $this->assertSame(['timeout_seconds' => 600], UserToolUpdateRequest::timeoutSeconds(600)->toArray());
        $this->assertSame(
            ['parameters' => ['type' => 'object']],
            UserToolUpdateRequest::parameters(['type' => 'object'])->toArray(),
        );
    }
}
