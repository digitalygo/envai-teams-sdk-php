<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Diff;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\Diff\DefinitionDiff;
use Digitalygo\Envai\Sync\Diff\DiffKind;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

final class DefinitionDiffTest extends SyncTestCase
{
    public function test_identical_payloads_are_empty(): void
    {
        $payload = ['name' => 'orchestrator', 'mode' => 'primary', 'nested' => ['a' => 1]];

        $diff = DefinitionDiff::between($payload, $payload);

        $this->assertTrue($diff->isEmpty());
        $this->assertSame([], $diff->entries);
    }

    public function test_added_key_produces_an_added_entry(): void
    {
        $diff = DefinitionDiff::between(['a' => 1], []);

        $this->assertCount(1, $diff->entries);
        $this->assertSame('a', $diff->entries[0]->path);
        $this->assertSame(DiffKind::Added, $diff->entries[0]->kind);
        $this->assertNull($diff->entries[0]->old);
        $this->assertSame(1, $diff->entries[0]->new);
    }

    public function test_removed_key_produces_a_removed_entry(): void
    {
        $diff = DefinitionDiff::between([], ['a' => 1]);

        $this->assertCount(1, $diff->entries);
        $this->assertSame('a', $diff->entries[0]->path);
        $this->assertSame(DiffKind::Removed, $diff->entries[0]->kind);
        $this->assertSame(1, $diff->entries[0]->old);
        $this->assertNull($diff->entries[0]->new);
    }

    public function test_changed_scalar_produces_a_changed_entry(): void
    {
        $diff = DefinitionDiff::between(['timeout_seconds' => 60], ['timeout_seconds' => 30]);

        $this->assertCount(1, $diff->entries);
        $this->assertSame(DiffKind::Changed, $diff->entries[0]->kind);
        $this->assertSame(30, $diff->entries[0]->old);
        $this->assertSame(60, $diff->entries[0]->new);
    }

    public function test_int_and_float_of_same_value_are_equal(): void
    {
        $this->assertTrue(DefinitionDiff::between(['x' => 1], ['x' => 1.0])->isEmpty());
        $this->assertTrue(DefinitionDiff::between(['x' => 30], ['x' => 30.0])->isEmpty());
    }

    public function test_null_vs_absent_are_different(): void
    {
        $diff = DefinitionDiff::between(['description' => null], []);

        $this->assertCount(1, $diff->entries);
        $this->assertSame(DiffKind::Added, $diff->entries[0]->kind);
        $this->assertNull($diff->entries[0]->new);
    }

    public function test_nested_object_keys_use_dot_notation(): void
    {
        $diff = DefinitionDiff::between(
            ['permissions' => ['files' => ['read' => true]]],
            ['permissions' => ['files' => ['read' => false]]],
        );

        $this->assertCount(1, $diff->entries);
        $this->assertSame('permissions.files.read', $diff->entries[0]->path);
        $this->assertFalse($diff->entries[0]->old);
        $this->assertTrue($diff->entries[0]->new);
    }

    public function test_object_members_are_keywise_order_insensitive(): void
    {
        $diff = DefinitionDiff::between(
            ['b' => 2, 'a' => 1],
            ['a' => 1, 'b' => 2],
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function test_list_items_are_index_sensitive(): void
    {
        $diff = DefinitionDiff::between(
            ['members' => [['name' => 'a'], ['name' => 'b']]],
            ['members' => [['name' => 'b'], ['name' => 'a']]],
        );

        $this->assertCount(2, $diff->entries);
        $this->assertSame('members[0].name', $diff->entries[0]->path);
        $this->assertSame('members[1].name', $diff->entries[1]->path);
    }

    public function test_list_length_change_marks_added_and_removed_indices(): void
    {
        $diff = DefinitionDiff::between(
            ['tools' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]],
            ['tools' => [['name' => 'a']]],
        );

        $paths = array_map(static fn ($entry): string => $entry->path, $diff->entries);

        $this->assertContains('tools[1]', $paths);
        $this->assertContains('tools[2]', $paths);
        $this->assertSame(DiffKind::Added, $diff->entries[0]->kind);
        $this->assertSame(['name' => 'b'], $diff->entries[0]->new);
    }

    public function test_empty_plain_array_matches_empty_object(): void
    {
        $this->assertTrue(DefinitionDiff::between(['config' => []], ['config' => []])->isEmpty());
        $this->assertTrue(DefinitionDiff::between(['config' => []], ['config' => new JsonObject([])])->isEmpty());
    }

    public function test_empty_plain_array_matches_empty_json_array(): void
    {
        $this->assertTrue(DefinitionDiff::between(['config' => []], ['config' => new JsonArray([])])->isEmpty());
    }

    public function test_json_object_wrapper_distinguishes_empty_object_from_list(): void
    {
        $diff = DefinitionDiff::between(
            ['config' => new JsonObject([])],
            ['config' => new JsonArray([])],
        );

        $this->assertFalse($diff->isEmpty());
        $this->assertCount(1, $diff->entries);
        $this->assertSame('config', $diff->entries[0]->path);
    }

    public function test_json_objects_are_unwrapped_for_comparison(): void
    {
        $diff = DefinitionDiff::between(
            ['config' => new JsonObject(['max_tokens' => 2000])],
            ['config' => ['max_tokens' => 2000]],
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function test_nested_list_inside_json_object(): void
    {
        $diff = DefinitionDiff::between(
            ['parameters' => new JsonObject(['enum' => ['a', 'b']])],
            ['parameters' => new JsonObject(['enum' => ['a']])],
        );

        $this->assertCount(1, $diff->entries);
        $this->assertSame('parameters.enum[1]', $diff->entries[0]->path);
    }

    public function test_render_contains_human_readable_lines(): void
    {
        $diff = DefinitionDiff::between(
            ['timeout_seconds' => 60, 'description' => 'New'],
            ['timeout_seconds' => 30],
        );

        $rendered = $diff->render();

        $this->assertStringContainsString('~ timeout_seconds: 30 -> 60', $rendered);
        $this->assertStringContainsString('+ description: "New"', $rendered);
    }

    public function test_render_shows_removed_value(): void
    {
        $diff = DefinitionDiff::between([], ['mode' => 'primary']);

        $this->assertStringContainsString('- mode: "primary"', $diff->render());
    }

    public function test_deeply_nested_changes_produce_multiple_entries(): void
    {
        $diff = DefinitionDiff::between(
            ['tools' => [['name' => 'a', 'config' => ['x' => 1, 'y' => 2]]]],
            ['tools' => [['name' => 'a', 'config' => ['x' => 1, 'y' => 3]]]],
        );

        $this->assertCount(1, $diff->entries);
        $this->assertSame('tools[0].config.y', $diff->entries[0]->path);
    }

    public function test_entries_are_ordered_by_encounter(): void
    {
        $diff = DefinitionDiff::between(['z' => 1, 'a' => 2], []);

        $this->assertSame(['z', 'a'], array_map(static fn ($entry): string => $entry->path, $diff->entries));
    }
}
