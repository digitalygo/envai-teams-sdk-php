<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\ChatCron\ChatCron;
use Digitalygo\Envai\Models\ChatCron\ChatCronFilters;
use Digitalygo\Envai\Models\ChatCron\ChatCronStatus;
use Digitalygo\Envai\Models\ChatCron\ChatCronTargetMode;
use Digitalygo\Envai\Models\ChatCron\ChatCronUpdateRequest;
use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Tests\Client\ManagementFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ChatCron DTO, filter and partial-update request round trips and validation. */
final class ChatCronModelTest extends TestCase
{
    public function test_from_array_round_trips_every_field_and_preserves_extra(): void
    {
        $payload = ManagementFixtures::chatCron(['future_field' => ['a' => 1], 'server_note' => 'new']);
        $cron = ChatCron::fromArray($payload);

        self::assertSame('cron_01j5abcdefghjkmnpqrstvwxyz', $cron->id);
        self::assertSame(ChatCronTargetMode::SameChat, $cron->targetMode);
        self::assertSame(ChatCronStatus::Active, $cron->status);
        self::assertSame(15, $cron->intervalMinutes);
        self::assertSame(0, $cron->scheduledCount);
        self::assertNull($cron->until);
        self::assertSame(['a' => 1], $cron->extra['future_field'] ?? null);
        self::assertSame('new', $cron->extra['server_note'] ?? null);
        self::assertSame($payload, $cron->toArray());
    }

    public function test_enum_round_trips_all_cases(): void
    {
        foreach (ChatCronTargetMode::cases() as $mode) {
            $cron = ChatCron::fromArray(ManagementFixtures::chatCron(['target_mode' => $mode->value]));
            self::assertSame($mode, $cron->targetMode);
            self::assertSame($mode->value, $cron->toArray()['target_mode']);
        }
        foreach (ChatCronStatus::cases() as $status) {
            $cron = ChatCron::fromArray(ManagementFixtures::chatCron(['status' => $status->value]));
            self::assertSame($status, $cron->status);
            self::assertSame($status->value, $cron->toArray()['status']);
        }
    }

    public function test_absent_nullable_fields_default_to_null(): void
    {
        $payload = ManagementFixtures::chatCron();
        foreach (['interval_minutes', 'daily_at', 'timezone', 'start_at', 'repeat_count', 'until', 'next_due_at'] as $nullable) {
            unset($payload[$nullable]);
        }
        $cron = ChatCron::fromArray($payload);
        self::assertNull($cron->intervalMinutes);
        self::assertNull($cron->dailyAt);
        self::assertNull($cron->timezone);
        self::assertNull($cron->startAt);
        self::assertNull($cron->repeatCount);
        self::assertNull($cron->until);
        self::assertNull($cron->nextDueAt);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $base = ManagementFixtures::chatCron();
        yield 'missing id' => [self::without($base, 'id')];
        yield 'id wrong type' => [self::with($base, 'id', 42)];
        yield 'missing max_until' => [self::without($base, 'max_until')];
        yield 'scheduled_count wrong type' => [self::with($base, 'scheduled_count', '0')];
        yield 'delivery_count missing' => [self::without($base, 'delivery_count')];
        yield 'interval_minutes wrong type' => [self::with($base, 'interval_minutes', '15')];
        yield 'repeat_count wrong type' => [self::with($base, 'repeat_count', 1.5)];
        yield 'daily_at wrong type' => [self::with($base, 'daily_at', 900)];
        yield 'unknown target_mode' => [self::with($base, 'target_mode', 'sideways')];
        yield 'unknown status' => [self::with($base, 'status', 'paused')];
        yield 'target_mode wrong type' => [self::with($base, 'target_mode', 1)];
        yield 'prompt wrong type' => [self::with($base, 'prompt', null)];
        yield 'next_due_at wrong type' => [self::with($base, 'next_due_at', false)];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_are_rejected(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChatCron::fromArray($payload);
    }

    public function test_filters_serialize_only_present_values(): void
    {
        self::assertSame([], (new ChatCronFilters)->toArray());
        self::assertSame(['origin_chat_id' => 'cht_x'], (new ChatCronFilters(originChatId: 'cht_x'))->toArray());
        self::assertSame(['status' => 'completed'], (new ChatCronFilters(status: ChatCronStatus::Completed))->toArray());
        self::assertSame([
            'origin_chat_id' => 'cht_x',
            'status' => 'active',
        ], (new ChatCronFilters(originChatId: 'cht_x', status: ChatCronStatus::Active))->toArray());
    }

    public function test_empty_update_request_omits_every_field(): void
    {
        $request = new ChatCronUpdateRequest;
        self::assertSame([], $request->toArray());
        self::assertTrue($request->prompt->isAbsent());
        self::assertTrue($request->targetMode->isAbsent());
    }

    public function test_update_request_distinguishes_absent_present_and_explicit_null(): void
    {
        $request = new ChatCronUpdateRequest(
            prompt: OptionalField::of('New prompt'),
            intervalMinutes: OptionalField::of(null),
            dailyAt: OptionalField::of('09:30'),
            repeatCount: OptionalField::of(3),
            until: OptionalField::absent(),
        );
        self::assertSame([
            'prompt' => 'New prompt',
            'interval_minutes' => null,
            'daily_at' => '09:30',
            'repeat_count' => 3,
        ], $request->toArray());
    }

    public function test_update_request_never_emits_immutable_scope_fields(): void
    {
        $request = new ChatCronUpdateRequest(
            targetMode: OptionalField::of(ChatCronTargetMode::NewChat),
            prompt: OptionalField::of('p'),
            intervalMinutes: OptionalField::of(5),
            dailyAt: OptionalField::of(null),
            timezone: OptionalField::of('UTC'),
            startAt: OptionalField::of('2026-11-12T00:00:00+00:00'),
            repeatCount: OptionalField::of(null),
            until: OptionalField::of(null),
        );
        $payload = $request->toArray();
        foreach (['workspace_id', 'project_id', 'origin_chat_id', 'team_id', 'status', 'scheduled_count', 'delivery_count', 'next_due_at', 'max_until', 'created_at', 'updated_at', 'api_key_id', 'turn_id', 'call_id'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload);
        }
        self::assertSame('new_chat', $payload['target_mode']);
    }

    public function test_update_request_static_factories(): void
    {
        self::assertSame(['target_mode' => 'same_chat'], ChatCronUpdateRequest::targetMode(ChatCronTargetMode::SameChat)->toArray());
        self::assertSame(['prompt' => 'x'], ChatCronUpdateRequest::prompt('x')->toArray());
        self::assertSame(['interval_minutes' => null], ChatCronUpdateRequest::intervalMinutes(null)->toArray());
        self::assertSame(['daily_at' => null], ChatCronUpdateRequest::dailyAt(null)->toArray());
        self::assertSame(['timezone' => 'UTC'], ChatCronUpdateRequest::timezone('UTC')->toArray());
        self::assertSame(['start_at' => null], ChatCronUpdateRequest::startAt(null)->toArray());
        self::assertSame(['repeat_count' => 2], ChatCronUpdateRequest::repeatCount(2)->toArray());
        self::assertSame(['until' => null], ChatCronUpdateRequest::until(null)->toArray());
    }

    /** @return iterable<string, array{ChatCronUpdateRequest}> */
    public static function invalidUpdateRequests(): iterable
    {
        yield 'wrong enum holder' => [new ChatCronUpdateRequest(targetMode: OptionalField::of('new_chat'))];
        yield 'prompt not string' => [new ChatCronUpdateRequest(prompt: OptionalField::of(5))];
        yield 'interval not int' => [new ChatCronUpdateRequest(intervalMinutes: OptionalField::of('15'))];
        yield 'daily_at not string' => [new ChatCronUpdateRequest(dailyAt: OptionalField::of(930))];
        yield 'timezone not string' => [new ChatCronUpdateRequest(timezone: OptionalField::of(false))];
        yield 'start_at not string' => [new ChatCronUpdateRequest(startAt: OptionalField::of(1))];
        yield 'repeat_count not int' => [new ChatCronUpdateRequest(repeatCount: OptionalField::of(1.5))];
        yield 'until not string' => [new ChatCronUpdateRequest(until: OptionalField::of([]))];
    }

    #[DataProvider('invalidUpdateRequests')]
    public function test_update_request_rejects_wrong_field_types(ChatCronUpdateRequest $request): void
    {
        $this->expectException(\LogicException::class);
        $request->toArray();
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
