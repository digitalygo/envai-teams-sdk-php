<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Reporting\ProjectActivityAnalytics;
use Digitalygo\Envai\Models\Reporting\ProjectEventTypeFilters;
use Digitalygo\Envai\Models\Reporting\ProjectLogEntry;
use Digitalygo\Envai\Models\Reporting\ReportingWire;
use Digitalygo\Envai\Models\Reporting\UsageKeyBreakdown;
use Digitalygo\Envai\Models\Reporting\UsageMediaTotals;
use Digitalygo\Envai\Models\Reporting\UsageMoney;
use Digitalygo\Envai\Models\Reporting\UsageReportDay;
use PHPUnit\Framework\TestCase;

final class ReportingTest extends TestCase
{
    public function test_usage_models_preserve_exact_values_and_extra_fields(): void
    {
        $payload = self::usageDayPayload();
        $day = UsageReportDay::fromArray($payload);

        self::assertSame('1.250000000000', $day->totals[1]->amount);
        self::assertSame('USD', $day->totals[1]->currency);
        self::assertSame(1, $day->unknownInvocations);
        self::assertSame(0, $day->notSubmittedInvocations);
        self::assertSame(0, $day->completionCount);
        self::assertNull($day->byProject);
        self::assertSame('kept', $day->extra['future_marker']);
        self::assertSame('0.100000000000', $day->media->totals[0]->amount);
        self::assertSame(1, $day->media->unknownCurrencyObservations);
        self::assertSame(1, $day->media->byApiKey[0]->unknownCurrencyObservations);
        self::assertEquals($payload, $day->toArray());
        self::assertStringContainsString('"by_project":null', json_encode($day, JSON_THROW_ON_ERROR));

        $withProjects = UsageReportDay::fromArray(self::usageDayPayload(['by_project' => [['marker' => 'kept']]]));
        self::assertStringContainsString('"by_project":[{"marker":"kept"}]', json_encode($withProjects, JSON_THROW_ON_ERROR));
    }

    public function test_usage_models_reject_wire_type_failures(): void
    {
        $cases = [
            static fn () => UsageMoney::fromArray(['currency' => 'USD', 'amount' => 1.25]),
            static fn () => UsageMoney::fromArray(['currency' => 'usd', 'amount' => '1']),
            static fn () => UsageMoney::fromArray(['currency' => 'USD', 'amount' => '-1']),
            static fn () => UsageKeyBreakdown::fromArray(self::keyBreakdown(['invocation_count' => '0'])),
            static fn () => UsageKeyBreakdown::fromArray(self::keyBreakdown(['invocation_count' => -1])),
            static fn () => UsageKeyBreakdown::fromArray(self::keyBreakdown(['totals' => [['unexpected']]])),
            static fn () => UsageMediaTotals::fromArray(self::mediaTotals(['by_api_key' => [['unexpected']]])),
            static fn () => UsageMediaTotals::fromArray(self::mediaTotals(['unknown_observations' => '1'])),
            static fn () => UsageMediaTotals::fromArray(self::mediaTotals(['priced_observations' => -1])),
            static fn () => UsageReportDay::fromArray(self::usageDayPayload(['media' => ['unexpected']])),
            static fn () => UsageReportDay::fromArray(self::usageDayPayload(['by_project' => ['future' => 'invalid']])),
            static fn () => UsageReportDay::fromArray(self::usageDayPayload(['date' => '2026-02-30'])),
            static fn () => UsageReportDay::fromArray(self::usageDayPayload(['invocation_count' => -1])),
            static fn () => ReportingWire::objectArray(['field' => [0 => 'a', 'x' => 'b']], 'field', 'context'),
            static fn () => ReportingWire::modelList(['items' => ['invalid']], 'items', 'context', UsageMoney::class),
        ];

        $missing = self::usageDayPayload();
        unset($missing['by_project']);
        $cases[] = static fn () => UsageReportDay::fromArray($missing);

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Invalid usage report payload accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_project_log_entry_preserves_nullable_audit_and_rejects_metadata_arrays(): void
    {
        $payload = self::logEntryPayload();
        $entry = ProjectLogEntry::fromArray($payload);

        self::assertNull($entry->apiKeyId);
        self::assertNull($entry->actorApiKeyId);
        self::assertNull($entry->turnId);
        self::assertSame(0, $entry->seq);
        self::assertTrue($entry->agent->isEmpty());
        self::assertSame('ok', $entry->data->get('status'));
        self::assertSame('kept', $entry->extra['future_marker']);
        self::assertEquals($payload, $entry->toArray());
        self::assertStringContainsString('"agent":{}', json_encode($entry, JSON_THROW_ON_ERROR));

        $nonNull = ProjectLogEntry::fromArray(self::logEntryPayload([
            'api_key_id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'actor_api_key_id' => 'key_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'turn_id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
        ]));
        self::assertSame('key_01j5abcdefghjkmnpqrstvwxyz', $nonNull->apiKeyId);
        self::assertSame('key_01ARZ3NDEKTSV4RRFFQ69G5FAV', $nonNull->actorApiKeyId);
        self::assertSame('cmpl_01j5abcdefghjkmnpqrstvwxyz', $nonNull->turnId);

        $missing = self::logEntryPayload();
        unset($missing['api_key_id']);
        $cases = [
            self::logEntryPayload(['agent' => ['unexpected']]),
            self::logEntryPayload(['data' => 'invalid']),
            self::logEntryPayload(['api_key_id' => 1]),
            self::logEntryPayload(['seq' => '0']),
            self::logEntryPayload(['seq' => -1]),
            $missing,
        ];

        foreach ($cases as $case) {
            try {
                ProjectLogEntry::fromArray($case);
                self::fail('Invalid project log entry accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_activity_analytics_preserves_stable_nested_fields_and_extra(): void
    {
        $payload = self::analyticsPayload();
        $analytics = ProjectActivityAnalytics::fromArray($payload);

        self::assertSame('UTC', $analytics->window->get('timezone'));
        self::assertNull($analytics->window->get('api_key_id'));
        self::assertNull($analytics->coverage->get('oldest_observed_at'));
        $tool = $analytics->durations->get('tool_execution');
        self::assertIsArray($tool);
        self::assertNull($tool['avg_ms']);
        self::assertCount(1, $analytics->daily->data);
        self::assertCount(1, $analytics->fileReads->data);
        self::assertSame('kept', $analytics->extra['future_field']);
        self::assertEquals($payload, $analytics->toArray());
        self::assertStringContainsString('"window":{', json_encode($analytics, JSON_THROW_ON_ERROR));
    }

    public function test_activity_analytics_rejects_wire_type_failures(): void
    {
        $cases = [
            self::analyticsPayload(['window' => ['unexpected']]),
            self::analyticsPayload(['daily' => ['future' => 'invalid']]),
            self::analyticsPayload(['file_reads' => 'invalid']),
            self::analyticsPayload(['durations' => ['invalid']]),
        ];

        $missing = self::analyticsPayload();
        unset($missing['coverage']);
        $cases[] = $missing;

        foreach ($cases as $case) {
            try {
                ProjectActivityAnalytics::fromArray($case);
                self::fail('Invalid activity analytics payload accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_event_type_catalog_preserves_types_and_rejects_invalid_entries(): void
    {
        $payload = ['types' => ['agent.started', 'turn.completed'], 'future_catalog' => 7];
        $catalog = ProjectEventTypeFilters::fromArray($payload);

        self::assertSame(['agent.started', 'turn.completed'], $catalog->types);
        self::assertSame(7, $catalog->extra['future_catalog']);
        self::assertEquals($payload, $catalog->toArray());

        $cases = [
            ['types' => ['alpha', 1]],
            ['types' => ['']],
            ['types' => [str_repeat('a', 81)]],
            ['types' => ['alpha', 'alpha']],
            ['types' => ['first' => 'alpha']],
            ['types' => array_map(static fn (int $index): string => 'type.'.$index, range(0, 1000))],
            [],
        ];

        foreach ($cases as $case) {
            try {
                ProjectEventTypeFilters::fromArray($case);
                self::fail('Invalid event type catalog accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function usageDayPayload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-10-10',
            'totals' => [
                ['currency' => 'EUR', 'amount' => '0.100000000000'],
                ['currency' => 'USD', 'amount' => '1.250000000000'],
            ],
            'invocation_count' => 0,
            'unknown_invocations' => 1,
            'not_submitted_invocations' => 0,
            'completion_count' => 0,
            'by_api_key' => [self::keyBreakdown()],
            'media' => self::mediaTotals(),
            'by_project' => null,
            'future_marker' => 'kept',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function keyBreakdown(array $overrides = []): array
    {
        return array_merge([
            'api_key_id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'totals' => [['currency' => 'USD', 'amount' => '0']],
            'invocation_count' => 0,
            'unknown_invocations' => 0,
            'not_submitted_invocations' => 0,
            'completion_count' => 0,
            'unknown_currency_observations' => 1,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function mediaTotals(array $overrides = []): array
    {
        return array_merge([
            'totals' => [['currency' => 'USD', 'amount' => '0.100000000000']],
            'priced_observations' => 2,
            'unknown_observations' => 1,
            'unknown_currency_observations' => 1,
            'by_api_key' => [self::keyBreakdown()],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function logEntryPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt_01j5abcdefghjkmnpqrstvwxyz',
            'project_id' => 'prj_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'api_key_id' => null,
            'actor_api_key_id' => null,
            'chat_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'workspace_id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'seq' => 0,
            'turn_id' => null,
            'type' => 'agent.started',
            'ts' => '2026-10-10T12:00:00Z',
            'agent' => [],
            'data' => ['status' => 'ok'],
            'future_marker' => 'kept',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function analyticsPayload(array $overrides = []): array
    {
        return array_merge([
            'window' => [
                'from' => '2026-10-01',
                'to' => '2026-10-10',
                'timezone' => 'UTC',
                'api_key_id' => null,
                'chat_id' => null,
            ],
            'coverage' => [
                'source' => 'retained_chat_events',
                'aggregation' => 'all_retained_matching_rows',
                'execution_history_complete' => false,
                'configured_retention_days' => 30,
                'observed_event_count' => 0,
                'oldest_observed_at' => null,
                'newest_observed_at' => null,
            ],
            'daily' => [['date' => '2026-10-01', 'event_count' => 0]],
            'event_types' => ['items' => [], 'other_count' => 0, 'omitted_group_count' => 0],
            'tools' => ['items' => [], 'other_count' => 0, 'omitted_group_count' => 0, 'missing_name_count' => 0],
            'failures' => ['turn_event_count' => 0, 'agent_event_count' => 0, 'tool_event_count' => 0, 'tool_result_unknown_status_count' => 0],
            'durations' => [
                'tool_execution' => ['avg_ms' => null, 'buckets' => []],
                'turn_execution' => ['avg_ms' => null, 'buckets' => []],
            ],
            'file_reads' => [['tool_name' => 'read', 'count' => 0]],
            'future_field' => 'kept',
        ], $overrides);
    }
}
