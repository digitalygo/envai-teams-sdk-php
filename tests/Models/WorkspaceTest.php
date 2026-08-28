<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Workspace\Workspace;
use Digitalygo\Envai\Models\Workspace\WorkspaceCreateRequest;
use Digitalygo\Envai\Models\Workspace\WorkspaceUpdateRequest;
use PHPUnit\Framework\TestCase;

final class WorkspaceTest extends TestCase
{
    public function test_workspace_hydrates_object_metadata_and_preserves_extra(): void
    {
        $workspace = Workspace::fromArray([
            'id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Product analytics',
            'metadata' => ['source' => 'onboarding', 'plan' => 'pro'],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
            'billing_tier' => 'pro',
        ]);

        $this->assertSame('wsp_01j5abcdefghjkmnpqrstvwxyz', $workspace->id);
        $this->assertSame('Product analytics', $workspace->name);
        $this->assertInstanceOf(JsonObject::class, $workspace->metadata);
        $this->assertSame('onboarding', $workspace->metadata->get('source'));
        $this->assertSame('2026-08-17T14:00:00+00:00', $workspace->createdAt);
        $this->assertSame(['billing_tier' => 'pro'], $workspace->extra);
    }

    public function test_workspace_hydrates_list_metadata_as_json_array(): void
    {
        $workspace = Workspace::fromArray([
            'id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Research',
            'metadata' => ['alpha', 'beta'],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ]);

        $this->assertInstanceOf(JsonArray::class, $workspace->metadata);
        $this->assertSame(['alpha', 'beta'], $workspace->metadata->toArray());
    }

    public function test_workspace_hydrates_empty_metadata_as_json_array(): void
    {
        $workspace = Workspace::fromArray([
            'id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Empty metadata',
            'metadata' => [],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ]);

        $this->assertInstanceOf(JsonArray::class, $workspace->metadata);
        $this->assertSame([], $workspace->metadata->toArray());
    }

    public function test_workspace_throws_on_missing_required_metadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Workspace payload is missing required field "metadata"');

        Workspace::fromArray([
            'id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Product analytics',
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ]);
    }

    public function test_workspace_round_trips_metadata_shape(): void
    {
        $payload = [
            'id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Product analytics',
            'metadata' => ['source' => 'onboarding'],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ];

        $this->assertSame($payload, Workspace::fromArray($payload)->toArray());
    }

    public function test_workspace_create_request_serializes_name_and_metadata(): void
    {
        $request = new WorkspaceCreateRequest(
            name: 'Product analytics',
            metadata: new JsonObject(['source' => 'onboarding']),
        );

        $this->assertSame([
            'name' => 'Product analytics',
            'metadata' => ['source' => 'onboarding'],
        ], $request->toArray());
    }

    public function test_workspace_create_request_omits_absent_metadata(): void
    {
        $request = new WorkspaceCreateRequest('Personal');

        $this->assertSame(['name' => 'Personal'], $request->toArray());
    }

    public function test_workspace_update_request_omits_absent_fields(): void
    {
        $request = new WorkspaceUpdateRequest;

        $this->assertSame([], $request->toArray());
    }

    public function test_workspace_update_request_serializes_provided_fields(): void
    {
        $request = new WorkspaceUpdateRequest(
            name: 'Renamed',
            metadata: new JsonArray(['a']),
        );

        $this->assertSame([
            'name' => 'Renamed',
            'metadata' => ['a'],
        ], $request->toArray());
    }
}
