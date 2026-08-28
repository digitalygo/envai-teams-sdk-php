<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Pagination;

use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Pagination\Page;
use Digitalygo\Envai\Pagination\PageLinks;
use Digitalygo\Envai\Pagination\PageMeta;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function test_page_builds_from_laravel_paginator_shape(): void
    {
        $page = Page::fromArray([
            'data' => [
                $this->completionPayload('cmpl_01j5abcdefghjkmnpqrstvwxy1', 'running'),
                $this->completionPayload('cmpl_01j5abcdefghjkmnpqrstvwxy2', 'succeeded'),
            ],
            'links' => [
                'first' => 'http://localhost:8081/api/v1/chats/cht_1/completions?page=1',
                'last' => 'http://localhost:8081/api/v1/chats/cht_1/completions?page=3',
                'prev' => null,
                'next' => 'http://localhost:8081/api/v1/chats/cht_1/completions?page=2',
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 3,
                'per_page' => 15,
                'to' => 15,
                'total' => 41,
            ],
        ], Completion::class);

        $this->assertSame(Completion::class, $page->itemClass);
        $this->assertCount(2, $page->items);
        $this->assertInstanceOf(Completion::class, $page->items[0]);
        $this->assertSame('cmpl_01j5abcdefghjkmnpqrstvwxy2', $page->items[1]->id);
        $this->assertSame(1, $page->meta->currentPage);
        $this->assertSame(3, $page->meta->lastPage);
        $this->assertSame(41, $page->meta->total);
        $this->assertNull($page->links->prev);
        $this->assertStringContainsString('page=2', (string) $page->links->next);
    }

    public function test_page_hydrates_empty_data_page(): void
    {
        $page = Page::fromArray([
            'data' => [],
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => [
                'current_page' => 1,
                'from' => null,
                'last_page' => 1,
                'per_page' => 15,
                'to' => null,
                'total' => 0,
            ],
        ], Completion::class);

        $this->assertSame([], $page->items);
        $this->assertNull($page->meta->from);
        $this->assertNull($page->meta->to);
        $this->assertNull($page->links->first);
    }

    public function test_page_meta_preserves_extra(): void
    {
        $meta = PageMeta::fromArray([
            'current_page' => 2,
            'from' => 16,
            'last_page' => 3,
            'per_page' => 15,
            'to' => 30,
            'total' => 41,
            'path' => '/api/v1/chats/cht_1/completions',
        ]);

        $this->assertSame(2, $meta->currentPage);
        $this->assertSame(['path' => '/api/v1/chats/cht_1/completions'], $meta->extra);
    }

    public function test_page_links_preserves_extra(): void
    {
        $links = PageLinks::fromArray([
            'first' => 'https://example.com?page=1',
            'last' => 'https://example.com?page=9',
            'prev' => null,
            'next' => 'https://example.com?page=3',
            'self' => 'https://example.com?page=2',
        ]);

        $this->assertSame('https://example.com?page=1', $links->first);
        $this->assertSame('https://example.com?page=9', $links->last);
        $this->assertSame(['self' => 'https://example.com?page=2'], $links->extra);
    }

    public function test_page_round_trips_through_to_array(): void
    {
        $payload = [
            'data' => [$this->completionPayload('cmpl_01j5abcdefghjkmnpqrstvwxy1', 'succeeded')],
            'links' => [
                'first' => 'https://example.com?page=1',
                'last' => 'https://example.com?page=1',
                'prev' => null,
                'next' => null,
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 1,
                'per_page' => 15,
                'to' => 1,
                'total' => 1,
            ],
        ];

        $page = Page::fromArray($payload, Completion::class);

        $this->assertSame($payload, $page->toArray());
    }

    public function test_page_throws_on_missing_links(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Page payload field "links" must be an object.');

        Page::fromArray([
            'data' => [],
            'meta' => ['current_page' => 1, 'from' => null, 'last_page' => 1, 'per_page' => 15, 'to' => null, 'total' => 0],
        ], Completion::class);
    }

    public function test_page_throws_on_item_class_without_array_hydratable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item class stdClass must implement');

        Page::fromArray(['data' => [], 'links' => [], 'meta' => []], \stdClass::class);
    }

    public function test_page_constructs_directly(): void
    {
        $completion = Completion::fromArray($this->completionPayload('cmpl_01j5abcdefghjkmnpqrstvwxy1', 'succeeded'));

        $page = new Page(
            items: [$completion],
            links: new PageLinks(null, null, null, null),
            meta: new PageMeta(1, 1, 1, 15, 1, 1),
            itemClass: Completion::class,
        );

        $this->assertSame([$completion], $page->items);
        $this->assertSame(1, $page->meta->total);
    }

    /**
     * @return array<string, mixed>
     */
    private function completionPayload(string $id, string $status): array
    {
        return [
            'id' => $id,
            'workspace_id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'chat_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'model_orchestrator' => 'anthropic/claude-sonnet-4',
            'models_subagents' => [],
            'started_at' => '2026-08-17T14:00:00+00:00',
            'usage' => ['prompt_tokens' => 120],
            'cost_usd' => 0.42,
            'status' => $status,
            'finish_reason' => $status === 'running' ? null : 'stop',
            'finished_at' => null,
            'duration_ms' => null,
            'content_hash' => null,
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ];
    }
}
