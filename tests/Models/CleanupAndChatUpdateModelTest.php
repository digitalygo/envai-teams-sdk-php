<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Chat\ChatUpdateRequest;
use Digitalygo\Envai\Models\Common\CleanupIntentState;
use Digitalygo\Envai\Models\Common\CleanupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cleanup outcome/intent DTOs and the chat title update request. */
final class CleanupAndChatUpdateModelTest extends TestCase
{
    public function test_cleanup_result_round_trips_nested_and_top_level_extra(): void
    {
        $payload = [
            'resource_type' => 'chat',
            'resource_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'cleanup' => [[
                'id' => 'cln_01j5abcdefghjkmnpqrstvwxyz',
                'kind' => 'chat',
                'status' => 'processing',
                'attempts' => 2,
                'deadline_at' => '2026-11-11T00:00:00+00:00',
                'started_at' => '2026-11-10T09:00:00+00:00',
                'finished_at' => null,
                'last_error_code' => 'retry_pending',
                'future_flag' => true,
            ]],
            'new_field' => 'value',
        ];

        $result = CleanupResult::fromArray($payload);

        self::assertSame('chat', $result->resourceType);
        self::assertSame('cht_01j5abcdefghjkmnpqrstvwxyz', $result->resourceId);
        self::assertCount(1, $result->cleanup);
        self::assertInstanceOf(CleanupIntentState::class, $result->cleanup[0]);
        self::assertSame('processing', $result->cleanup[0]->status);
        self::assertSame(2, $result->cleanup[0]->attempts);
        self::assertSame('retry_pending', $result->cleanup[0]->lastErrorCode);
        self::assertSame('value', $result->extra['new_field'] ?? null);
        self::assertSame($payload, $result->toArray());
    }

    public function test_empty_cleanup_list_is_valid(): void
    {
        $payload = ['resource_type' => 'workspace', 'resource_id' => 'wsp_x', 'cleanup' => []];
        $result = CleanupResult::fromArray($payload);
        self::assertSame([], $result->cleanup);
        self::assertSame($payload, $result->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCleanupResults(): iterable
    {
        $entry = [
            'id' => 'cln_1', 'kind' => 'chat', 'status' => 'pending', 'attempts' => 0,
            'deadline_at' => null, 'started_at' => null, 'finished_at' => null, 'last_error_code' => null,
        ];
        $base = ['resource_type' => 'chat', 'resource_id' => 'cht_x', 'cleanup' => [$entry]];

        yield 'cleanup missing' => [['resource_type' => 'chat', 'resource_id' => 'cht_x']];
        yield 'cleanup not list' => [self::with($base, 'cleanup', ['x' => $entry])];
        yield 'cleanup scalar' => [self::with($base, 'cleanup', 'nope')];
        yield 'entry scalar' => [self::with($base, 'cleanup', [false])];
        yield 'entry is list' => [self::with($base, 'cleanup', [[]])];
        yield 'missing resource_type' => [self::without($base, 'resource_type')];
        yield 'resource_type wrong type' => [self::with($base, 'resource_type', 1)];
        yield 'missing resource_id' => [self::without($base, 'resource_id')];
        yield 'entry missing id' => [self::with($base, 'cleanup', [self::without($entry, 'id')])];
        yield 'entry attempts wrong type' => [self::with($base, 'cleanup', [self::with($entry, 'attempts', '0')])];
        yield 'entry status wrong type' => [self::with($base, 'cleanup', [self::with($entry, 'status', 5)])];
        yield 'entry deadline wrong type' => [self::with($base, 'cleanup', [self::with($entry, 'deadline_at', 12)])];
    }

    #[DataProvider('invalidCleanupResults')]
    public function test_invalid_cleanup_results_are_rejected(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CleanupResult::fromArray($payload);
    }

    public function test_cleanup_intent_absent_nullable_fields_default_to_null(): void
    {
        $state = CleanupIntentState::fromArray([
            'id' => 'cln_1', 'kind' => 'chat', 'status' => 'pending', 'attempts' => 0,
        ]);
        self::assertNull($state->deadlineAt);
        self::assertNull($state->startedAt);
        self::assertNull($state->finishedAt);
        self::assertNull($state->lastErrorCode);
        self::assertSame([], $state->extra);
        self::assertSame([
            'id' => 'cln_1', 'kind' => 'chat', 'status' => 'pending', 'attempts' => 0,
            'deadline_at' => null, 'started_at' => null, 'finished_at' => null, 'last_error_code' => null,
        ], $state->toArray());
    }

    public function test_cleanup_intent_preserves_extra(): void
    {
        $state = CleanupIntentState::fromArray([
            'id' => 'cln_1', 'kind' => 'chat', 'status' => 'failed', 'attempts' => 4,
            'deadline_at' => null, 'started_at' => null, 'finished_at' => null, 'last_error_code' => 'worker_down',
            'new_field' => ['a' => 1],
        ]);
        self::assertSame(['a' => 1], $state->extra['new_field'] ?? null);
        self::assertSame(['a' => 1], $state->toArray()['new_field'] ?? null);
    }

    public function test_chat_update_request_serializes_title_or_null_clear(): void
    {
        self::assertSame(['title' => 'Q3 rollout plan'], (new ChatUpdateRequest('Q3 rollout plan'))->toArray());
        self::assertSame(['title' => null], (new ChatUpdateRequest(null))->toArray());
    }

    /** @param array<string, mixed> $base */
    private static function without(array $base, string $key): array
    {
        unset($base[$key]);

        return $base;
    }

    /** @param array<string, mixed> $base */
    private static function with(array $base, string $key, mixed $value): array
    {
        $base[$key] = $value;

        return $base;
    }
}
