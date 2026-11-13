<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\ProjectReportsService;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\AccessDeniedException;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\StrictValidationTransport;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Reporting\AnalyticsFilters;
use Digitalygo\Envai\Models\Reporting\EventLogFilters;
use Digitalygo\Envai\Models\Reporting\ProjectActivityAnalytics;
use Digitalygo\Envai\Models\Reporting\ProjectEventTypeFilters;
use Digitalygo\Envai\Models\Reporting\ProjectLogEntry;
use Digitalygo\Envai\Models\Reporting\ReportFilter;
use Digitalygo\Envai\Models\Reporting\TypeCatalogFilters;
use Digitalygo\Envai\Models\Reporting\UsageFilters;
use Digitalygo\Envai\Models\Reporting\UsageReportDay;
use Digitalygo\Envai\Pagination\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectReportsTest extends TestCase
{
    private const PROJECT_ID = 'prj_01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const OTHER_PROJECT_ID = 'prj_01j5abcdefghjkmnpqrstvwxyz';

    private const KEY_ID = 'key_01j5abcdefghjkmnpqrstvwxyz';

    private const KEY_ID_2 = 'key_01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const CHAT_ID = 'cht_01j5abcdefghjkmnpqrstvwxyz';

    private const WORKSPACE_ID = 'wsp_01j5abcdefghjkmnpqrstvwxyz';

    private const WORKSPACE_ID_2 = 'wsp_01ARZ3NDEKTSV4RRFFQ69G5FAV';

    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'lenient' => [false];
        yield 'strict' => [true];
    }

    #[DataProvider('modes')]
    public function test_envai_client_project_reports_uses_strict_stack(bool $strict): void
    {
        $transport = new FakeTransport;
        $payload = self::usagePagePayload(1, 1);
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $client = new EnvaiClient(
            new EnvaiConfig('https://example.test', new SensitiveString('test-project-key'), strictValidation: $strict),
            $transport,
        );

        $page = $client->projectReports()->usage(self::PROJECT_ID, new UsageFilters(
            from: '2026-10-01',
            to: '2026-10-10',
        ));

        self::assertInstanceOf(UsageReportDay::class, $page->items[0]);
        self::assertCount(1, $transport->requests);
        self::assertSame([], $transport->preflightRequests);
        $this->assertSafeGet($transport->requests[0], 'from=2026-10-01&to=2026-10-10', 'usage');
    }

    #[DataProvider('modes')]
    public function test_usage_page_preserves_decimal_strings_nulls_zeroes_and_extras(bool $strict): void
    {
        $transport = new FakeTransport;
        $payload = self::usagePagePayload(1, 2);
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $page = $service->usage(self::PROJECT_ID, new UsageFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            perPage: 25,
        ));

        self::assertInstanceOf(Page::class, $page);
        self::assertCount(1, $page->items);
        $day = $page->items[0];
        self::assertInstanceOf(UsageReportDay::class, $day);
        self::assertSame('0.100000000000', $day->totals[0]->amount);
        self::assertSame('EUR', $day->totals[0]->currency);
        self::assertSame('1.250000000000', $day->totals[1]->amount);
        self::assertSame('USD', $day->totals[1]->currency);
        self::assertSame(0, $day->invocationCount);
        self::assertSame(1, $day->unknownInvocations);
        self::assertSame(0, $day->notSubmittedInvocations);
        self::assertSame(0, $day->completionCount);
        self::assertNull($day->byProject);
        self::assertSame('kept', $day->extra['future_marker']);
        self::assertEquals($payload, $page->toArray());
        self::assertSame('https://remote.invalid/api/v1/projects/'.self::OTHER_PROJECT_ID.'/usage?page=2', $page->links->next);
        self::assertCount(1, $transport->requests);
        self::assertSame([], $transport->preflightRequests);
        $this->assertSafeGet(
            $transport->requests[0],
            'from=2026-10-01&to=2026-10-10&api_key_id='.self::KEY_ID.'&per_page=25',
            'usage',
        );
    }

    #[DataProvider('modes')]
    public function test_logs_events_page_preserves_metadata_and_empty_objects(bool $strict): void
    {
        $transport = new FakeTransport;
        $payload = self::logPagePayload(1, 2);
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $page = $service->logs(self::PROJECT_ID, new EventLogFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            chatId: self::CHAT_ID,
            type: 'agent.started',
            perPage: 25,
        ));

        self::assertCount(1, $page->items);
        $entry = $page->items[0];
        self::assertInstanceOf(ProjectLogEntry::class, $entry);
        self::assertNull($entry->apiKeyId);
        self::assertNull($entry->actorApiKeyId);
        self::assertNull($entry->turnId);
        self::assertSame(0, $entry->seq);
        self::assertTrue($entry->agent->isEmpty());
        self::assertTrue($entry->data->isEmpty());
        self::assertSame('kept', $entry->extra['future_marker']);
        self::assertEquals($payload, $page->toArray());
        self::assertStringContainsString('"agent":{}', json_encode($entry, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('"data":{}', json_encode($entry, JSON_THROW_ON_ERROR));
        self::assertSame('https://remote.invalid/api/v1/projects/'.self::OTHER_PROJECT_ID.'/logs?page=2', $page->links->next);
        self::assertCount(1, $transport->requests);
        self::assertSame([], $transport->preflightRequests);
        $this->assertSafeGet(
            $transport->requests[0],
            'view=events&from=2026-10-01&to=2026-10-10&api_key_id='.self::KEY_ID.'&chat_id='.self::CHAT_ID.'&type=agent.started&per_page=25',
            'logs',
        );
    }

    #[DataProvider('modes')]
    public function test_indexed_list_encoding_uses_http_build_query_and_stays_local(bool $strict): void
    {
        $transport = new FakeTransport;
        $payload = self::logPagePayload(2, 2);
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $page = $service->logsPage(self::PROJECT_ID, 2, new EventLogFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            types: ['alpha', 'beta'],
            workspaceIds: [self::WORKSPACE_ID, self::WORKSPACE_ID_2],
            apiKeyIds: [self::KEY_ID, self::KEY_ID_2],
            page: 2,
            perPage: 50,
        ));

        self::assertSame(2, $page->meta->currentPage);
        self::assertCount(1, $transport->requests);
        $expected = 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'types' => ['alpha', 'beta'],
            'workspace_ids' => [self::WORKSPACE_ID, self::WORKSPACE_ID_2],
            'api_key_ids' => [self::KEY_ID, self::KEY_ID_2],
            'page' => 2,
            'per_page' => 50,
        ], '', '&', PHP_QUERY_RFC3986);
        $this->assertSafeGet($transport->requests[0], $expected, 'logs');
    }

    #[DataProvider('modes')]
    public function test_analytics_envelope_uses_stable_json_objects_and_nulls(bool $strict): void
    {
        $transport = new FakeTransport;
        $payload = ['data' => self::analyticsPayload()];
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $analytics = $service->analytics(self::PROJECT_ID, new AnalyticsFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            chatId: self::CHAT_ID,
        ));

        self::assertInstanceOf(ProjectActivityAnalytics::class, $analytics);
        self::assertSame('UTC', $analytics->window->get('timezone'));
        self::assertSame(self::KEY_ID, $analytics->window->get('api_key_id'));
        self::assertSame(self::CHAT_ID, $analytics->window->get('chat_id'));
        self::assertNull($analytics->coverage->get('oldest_observed_at'));
        self::assertNull($analytics->coverage->get('newest_observed_at'));
        $tool = $analytics->durations->get('tool_execution');
        self::assertIsArray($tool);
        self::assertNull($tool['avg_ms']);
        self::assertNull($tool['p95_ms']);
        self::assertCount(2, $analytics->daily->data);
        self::assertCount(5, $analytics->fileReads->data);
        self::assertEquals(self::analyticsPayload(), $analytics->toArray());
        self::assertCount(1, $transport->requests);
        $this->assertSafeGet(
            $transport->requests[0],
            'view=analytics&from=2026-10-01&to=2026-10-10&api_key_id='.self::KEY_ID.'&chat_id='.self::CHAT_ID,
            'logs',
        );
    }

    #[DataProvider('modes')]
    public function test_type_catalog_envelope_and_event_types_alias(bool $strict): void
    {
        $transport = new FakeTransport;
        $payload = self::catalogPayload();
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $filters = new TypeCatalogFilters(from: '2026-10-01', to: '2026-10-10');
        $catalog = $service->filters(self::PROJECT_ID, $filters);
        $alias = $service->eventTypes(self::PROJECT_ID, $filters);

        self::assertInstanceOf(ProjectEventTypeFilters::class, $catalog);
        self::assertSame(['agent.started', 'turn.completed'], $catalog->types);
        self::assertEquals($payload['data'], $catalog->toArray());
        self::assertEquals($payload['data'], $alias->toArray());
        self::assertCount(2, $transport->requests);
        foreach ($transport->requests as $request) {
            $this->assertSafeGet($request, 'view=filters&from=2026-10-01&to=2026-10-10', 'logs');
        }
    }

    #[DataProvider('modes')]
    public function test_pagination_iterates_locally_without_following_remote_links(bool $strict): void
    {
        $transport = new FakeTransport;
        foreach ([self::usagePagePayload(1, 2), self::usagePagePayload(2, 2), self::logPagePayload(1, 2), self::logPagePayload(2, 2)] as $payload) {
            $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        }
        $service = $this->service($strict, $transport);
        $usage = new UsageFilters(from: '2026-10-01', to: '2026-10-10');
        $logs = new EventLogFilters(from: '2026-10-01', to: '2026-10-10');

        self::assertCount(2, iterator_to_array($service->iterateUsage(self::PROJECT_ID, $usage), false));
        self::assertCount(2, iterator_to_array($service->iterateLogs(self::PROJECT_ID, $logs), false));
        self::assertCount(4, $transport->requests);
        self::assertSame([], $transport->preflightRequests);

        foreach ($transport->requests as $request) {
            self::assertStringNotContainsString('remote.invalid', $request->uri);
            self::assertStringNotContainsString('/companies/', $request->uri);
            self::assertStringNotContainsString(self::OTHER_PROJECT_ID, $request->uri);
        }

        self::assertStringNotContainsString('page=', (string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY));
        self::assertStringContainsString('page=2', (string) parse_url($transport->requests[1]->uri, PHP_URL_QUERY));
        self::assertStringContainsString('view=events', (string) parse_url($transport->requests[2]->uri, PHP_URL_QUERY));
        self::assertStringContainsString('page=2', (string) parse_url($transport->requests[3]->uri, PHP_URL_QUERY));
    }

    #[DataProvider('modes')]
    public function test_explicit_page_selector_overrides_filter_page(bool $strict): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [], json_encode(self::usagePagePayload(1, 2), JSON_THROW_ON_ERROR)));
        $transport->queueResponse(new HttpResponse(200, [], json_encode(self::logPagePayload(2, 2), JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $usage = $service->usagePage(self::PROJECT_ID, 1, new UsageFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            page: 2,
            perPage: 25,
        ));
        $logs = $service->logsPage(self::PROJECT_ID, 2, new EventLogFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            chatId: self::CHAT_ID,
            type: 'agent.started',
            page: 1,
            perPage: 25,
        ));

        self::assertSame(1, $usage->meta->currentPage);
        self::assertSame(2, $logs->meta->currentPage);
        self::assertCount(2, $transport->requests);
        self::assertSame([], $transport->preflightRequests);

        $this->assertSafeGet($transport->requests[0], http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'page' => 1,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'usage');
        $this->assertSafeGet($transport->requests[1], 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'chat_id' => self::CHAT_ID,
            'type' => 'agent.started',
            'page' => 2,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'logs');
    }

    #[DataProvider('modes')]
    public function test_usage_and_logs_respect_filter_page(bool $strict): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [], json_encode(self::usagePagePayload(2, 2), JSON_THROW_ON_ERROR)));
        $transport->queueResponse(new HttpResponse(200, [], json_encode(self::logPagePayload(2, 2), JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        $usage = $service->usage(self::PROJECT_ID, new UsageFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            page: 2,
            perPage: 25,
        ));
        $logs = $service->logs(self::PROJECT_ID, new EventLogFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            chatId: self::CHAT_ID,
            type: 'agent.started',
            page: 2,
            perPage: 25,
        ));

        self::assertSame(2, $usage->meta->currentPage);
        self::assertSame(2, $logs->meta->currentPage);
        self::assertCount(2, $transport->requests);
        self::assertSame([], $transport->preflightRequests);

        $this->assertSafeGet($transport->requests[0], http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'page' => 2,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'usage');
        $this->assertSafeGet($transport->requests[1], 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'chat_id' => self::CHAT_ID,
            'type' => 'agent.started',
            'page' => 2,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'logs');
    }

    #[DataProvider('modes')]
    public function test_iterators_start_at_filter_page_and_preserve_non_page_filters(bool $strict): void
    {
        $transport = new FakeTransport;
        foreach ([self::usagePagePayload(2, 3), self::usagePagePayload(3, 3), self::logPagePayload(2, 3), self::logPagePayload(3, 3)] as $payload) {
            $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        }
        $service = $this->service($strict, $transport);
        $usage = new UsageFilters(from: '2026-10-01', to: '2026-10-10', apiKeyId: self::KEY_ID, page: 2, perPage: 25);
        $logs = new EventLogFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            chatId: self::CHAT_ID,
            type: 'agent.started',
            page: 2,
            perPage: 25,
        );

        self::assertCount(2, iterator_to_array($service->iterateUsage(self::PROJECT_ID, $usage), false));
        self::assertCount(2, iterator_to_array($service->iterateLogs(self::PROJECT_ID, $logs), false));
        self::assertCount(4, $transport->requests);
        self::assertSame([], $transport->preflightRequests);

        foreach ($transport->requests as $request) {
            self::assertStringNotContainsString('remote.invalid', $request->uri);
            self::assertStringNotContainsString('/companies/', $request->uri);
            self::assertStringNotContainsString(self::OTHER_PROJECT_ID, $request->uri);
        }

        $this->assertSafeGet($transport->requests[0], http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'page' => 2,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'usage');
        $this->assertSafeGet($transport->requests[1], http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'page' => 3,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'usage');
        $this->assertSafeGet($transport->requests[2], 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'chat_id' => self::CHAT_ID,
            'type' => 'agent.started',
            'page' => 2,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'logs');
        $this->assertSafeGet($transport->requests[3], 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'chat_id' => self::CHAT_ID,
            'type' => 'agent.started',
            'page' => 3,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'logs');
    }

    #[DataProvider('modes')]
    public function test_iterators_explicit_start_page_overrides_filter_page(bool $strict): void
    {
        $transport = new FakeTransport;
        foreach ([self::usagePagePayload(3, 4), self::usagePagePayload(4, 4), self::logPagePayload(2, 3), self::logPagePayload(3, 3)] as $payload) {
            $transport->queueResponse(new HttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        }
        $service = $this->service($strict, $transport);
        $usage = new UsageFilters(from: '2026-10-01', to: '2026-10-10', apiKeyId: self::KEY_ID, page: 1, perPage: 25);
        $logs = new EventLogFilters(
            from: '2026-10-01',
            to: '2026-10-10',
            apiKeyId: self::KEY_ID,
            chatId: self::CHAT_ID,
            type: 'agent.started',
            page: 5,
            perPage: 25,
        );

        self::assertCount(2, iterator_to_array($service->iterateUsage(self::PROJECT_ID, $usage, 3), false));
        self::assertCount(2, iterator_to_array($service->iterateLogs(self::PROJECT_ID, $logs, 2), false));
        self::assertCount(4, $transport->requests);
        self::assertSame([], $transport->preflightRequests);

        $this->assertSafeGet($transport->requests[0], http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'page' => 3,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'usage');
        $this->assertSafeGet($transport->requests[1], http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'page' => 4,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'usage');
        $this->assertSafeGet($transport->requests[2], 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'chat_id' => self::CHAT_ID,
            'type' => 'agent.started',
            'page' => 2,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'logs');
        $this->assertSafeGet($transport->requests[3], 'view=events&'.http_build_query([
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'api_key_id' => self::KEY_ID,
            'chat_id' => self::CHAT_ID,
            'type' => 'agent.started',
            'page' => 3,
            'per_page' => 25,
        ], '', '&', PHP_QUERY_RFC3986), 'logs');
    }

    #[DataProvider('modes')]
    public function test_iterators_reject_invalid_start_pages(bool $strict): void
    {
        $transport = new FakeTransport;
        $service = $this->service($strict, $transport);
        $cases = [
            static fn () => iterator_to_array($service->iterateUsage(self::PROJECT_ID, new UsageFilters(page: 2), 0), false),
            static fn () => iterator_to_array($service->iterateLogs(self::PROJECT_ID, new EventLogFilters(page: 2), 0), false),
            static fn () => iterator_to_array($service->iterateUsage(self::PROJECT_ID, new RawReportFilter(['page' => 0])), false),
            static fn () => iterator_to_array($service->iterateLogs(self::PROJECT_ID, new RawReportFilter(['page' => 0])), false),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Invalid iterator start page accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $transport->requests);
            }
        }

        self::assertSame([], $transport->preflightRequests);
    }

    #[DataProvider('modes')]
    public function test_iterators_reject_non_advancing_pages(bool $strict): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [], json_encode(self::usagePagePayload(9, 9), JSON_THROW_ON_ERROR)));
        $transport->queueResponse(new HttpResponse(200, [], json_encode(self::logPagePayload(9, 9), JSON_THROW_ON_ERROR)));
        $service = $this->service($strict, $transport);

        try {
            iterator_to_array($service->iterateUsage(self::PROJECT_ID), false);
            self::fail('Non-advancing usage pagination accepted.');
        } catch (ProtocolViolationException $error) {
            self::assertStringContainsString('Usage pagination', $error->getMessage());
        }

        try {
            iterator_to_array($service->iterateLogs(self::PROJECT_ID), false);
            self::fail('Non-advancing log pagination accepted.');
        } catch (ProtocolViolationException $error) {
            self::assertStringContainsString('Project log pagination', $error->getMessage());
        }

        self::assertCount(2, $transport->requests);
    }

    #[DataProvider('modes')]
    public function test_error_statuses_map_for_every_view(bool $strict): void
    {
        $cases = [
            403 => [AccessDeniedException::class, 'access_denied'],
            404 => [NotFoundException::class, 'not_found'],
            422 => [ValidationException::class, 'validation_error'],
            503 => [ServerException::class, 'server_error'],
        ];

        foreach ($cases as $status => [$class, $type]) {
            foreach (['usage', 'logs', 'analytics', 'filters'] as $view) {
                $transport = new FakeTransport;
                $transport->queueResponse(new HttpResponse($status, [], json_encode(self::errorPayload($type), JSON_THROW_ON_ERROR)));
                $service = $this->service($strict, $transport);
                $filters = match ($view) {
                    'usage' => new UsageFilters(from: '2026-10-01', to: '2026-10-10'),
                    'logs' => new EventLogFilters(from: '2026-10-01', to: '2026-10-10'),
                    'analytics' => new AnalyticsFilters(from: '2026-10-01', to: '2026-10-10'),
                    'filters' => new TypeCatalogFilters(from: '2026-10-01', to: '2026-10-10'),
                };

                try {
                    match ($view) {
                        'usage' => $service->usage(self::PROJECT_ID, $filters),
                        'logs' => $service->logs(self::PROJECT_ID, $filters),
                        'analytics' => $service->analytics(self::PROJECT_ID, $filters),
                        'filters' => $service->filters(self::PROJECT_ID, $filters),
                    };
                    self::fail('HTTP '.$status.' was not mapped for '.$view.'.');
                } catch (ApiException $error) {
                    self::assertInstanceOf($class, $error);
                    self::assertSame($status, $error->httpStatus);
                }

                self::assertCount(1, $transport->requests);
            }
        }
    }

    #[DataProvider('modes')]
    public function test_invalid_filters_fail_before_transport(bool $strict): void
    {
        $transport = new FakeTransport;
        $service = $this->service($strict, $transport);
        $cases = [
            static fn () => new UsageFilters(from: '2026-10-20', to: '2026-10-01'),
            static fn () => new UsageFilters(from: '2026-02-30'),
            static fn () => new UsageFilters(from: '2026-01-01', to: '2026-10-10'),
            static fn () => new UsageFilters(apiKeyId: 'key_1'),
            static fn () => new UsageFilters(perPage: 101),
            static fn () => new EventLogFilters(chatId: 'cht_1'),
            static fn () => new EventLogFilters(apiKeyId: self::KEY_ID, apiKeyIds: [self::KEY_ID_2]),
            static fn () => new EventLogFilters(type: 'alpha', types: ['beta']),
            static fn () => new EventLogFilters(types: []),
            static fn () => new EventLogFilters(types: ['alpha', 'alpha']),
            static fn () => new EventLogFilters(types: [['alpha']]),
            static fn () => new EventLogFilters(types: [1 => 'alpha']),
            static fn () => new EventLogFilters(types: array_map(static fn (int $index): string => 'type.'.$index, range(0, 50))),
            static fn () => new EventLogFilters(workspaceIds: [self::WORKSPACE_ID, self::WORKSPACE_ID]),
            static fn () => new EventLogFilters(apiKeyIds: [str_repeat('k', 31)]),
            static fn () => new AnalyticsFilters(from: '2026-10-20', to: '2026-10-01'),
            fn () => $service->analytics(self::PROJECT_ID, new RawReportFilter(['type' => 'alpha'])),
            fn () => $service->usage(self::PROJECT_ID, new RawReportFilter(['page' => 0])),
            fn () => $service->logs(self::PROJECT_ID, new RawReportFilter(['view' => 'analytics', 'page' => 1])),
            fn () => $service->filters(self::PROJECT_ID, new RawReportFilter(['page' => 1])),
            fn () => $service->usage(self::PROJECT_ID, new RawReportFilter(['from' => null])),
            fn () => $service->usage(self::PROJECT_ID, new RawReportFilter(['from' => 123])),
            fn () => $service->logs(self::PROJECT_ID, new RawReportFilter(['type' => 123])),
            fn () => $service->usage(self::PROJECT_ID, new RawReportFilter(['page' => '2'])),
            fn () => $service->usagePage(self::PROJECT_ID, 0),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Invalid project report query accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $transport->requests);
            }
        }

        self::assertSame([], $transport->preflightRequests);
    }

    public function test_non_strict_envelopes_preserve_extra_fields(): void
    {
        $transport = new FakeTransport;
        $analytics = self::analyticsPayload();
        $analytics['future_window'] = 'kept';
        $catalog = self::catalogPayload();
        $catalog['data']['future_catalog'] = 7;
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $analytics], JSON_THROW_ON_ERROR)));
        $transport->queueResponse(new HttpResponse(200, [], json_encode($catalog, JSON_THROW_ON_ERROR)));
        $service = $this->service(false, $transport);

        $report = $service->analytics(self::PROJECT_ID, new AnalyticsFilters(from: '2026-10-01', to: '2026-10-10'));
        $types = $service->filters(self::PROJECT_ID, new TypeCatalogFilters(from: '2026-10-01', to: '2026-10-10'));

        self::assertSame('kept', $report->extra['future_window']);
        self::assertSame(7, $types->extra['future_catalog']);
        self::assertEquals($analytics, $report->toArray());
        self::assertEquals($catalog['data'], $types->toArray());
        self::assertCount(2, $transport->requests);
    }

    private function service(bool $strict, FakeTransport $transport): ProjectReportsService
    {
        $uris = new UriResolver('https://example.test');
        $inner = $strict ? new StrictValidationTransport($transport, $uris) : $transport;
        $executor = new RequestExecutor($inner, $uris, null, null, new SensitiveString('test-project-key'));

        return new ProjectReportsService($executor, $uris);
    }

    private function assertSafeGet(HttpRequest $request, string $query, string $resource): void
    {
        self::assertSame('GET', $request->method);
        self::assertSame('https://example.test/api/v1/projects/'.self::PROJECT_ID.'/'.$resource.'?'.$query, $request->uri);
        self::assertArrayNotHasKey('x-envai-project-id', $request->headers);
        self::assertStringNotContainsString('/companies/', $request->uri);
        self::assertStringNotContainsString(self::OTHER_PROJECT_ID, $request->uri);
    }

    /** @return array<string, mixed> */
    private static function usagePagePayload(int $currentPage, int $lastPage): array
    {
        return [
            'data' => [self::usageDay()],
            'links' => self::links('usage', $lastPage, ['future_link' => 'kept']),
            'meta' => self::meta($currentPage, $lastPage, ['future_meta' => 7]),
        ];
    }

    /** @return array<string, mixed> */
    private static function logPagePayload(int $currentPage, int $lastPage): array
    {
        return [
            'data' => [[
                'id' => 'evt_01j5abcdefghjkmnpqrstvwxyz',
                'project_id' => self::PROJECT_ID,
                'api_key_id' => null,
                'actor_api_key_id' => null,
                'chat_id' => self::CHAT_ID,
                'workspace_id' => self::WORKSPACE_ID,
                'seq' => 0,
                'turn_id' => null,
                'type' => 'agent.started',
                'ts' => '2026-10-10T12:00:00Z',
                'agent' => [],
                'data' => [],
                'future_marker' => 'kept',
            ]],
            'links' => self::links('logs', $lastPage, ['future_link' => 'kept']),
            'meta' => self::meta($currentPage, $lastPage, ['future_meta' => 7]),
        ];
    }

    /** @return array<string, mixed> */
    private static function usageDay(): array
    {
        $key = [
            'api_key_id' => self::KEY_ID,
            'totals' => [['currency' => 'USD', 'amount' => '0']],
            'invocation_count' => 0,
            'unknown_invocations' => 0,
            'not_submitted_invocations' => 0,
            'completion_count' => 0,
            'unknown_currency_observations' => 1,
        ];

        return [
            'date' => '2026-10-10',
            'totals' => [
                ['currency' => 'EUR', 'amount' => '0.100000000000'],
                ['currency' => 'USD', 'amount' => '1.250000000000'],
            ],
            'invocation_count' => 0,
            'unknown_invocations' => 1,
            'not_submitted_invocations' => 0,
            'completion_count' => 0,
            'by_api_key' => [$key],
            'media' => [
                'totals' => [['currency' => 'USD', 'amount' => '2.500000000000']],
                'priced_observations' => 2,
                'unknown_observations' => 1,
                'unknown_currency_observations' => 1,
                'by_api_key' => [$key],
            ],
            'by_project' => null,
            'future_marker' => 'kept',
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function links(string $resource, int $lastPage, array $extra = []): array
    {
        return array_merge([
            'first' => 'https://example.test/api/v1/projects/'.self::PROJECT_ID.'/'.$resource.'?page=1',
            'last' => 'https://example.test/api/v1/projects/'.self::PROJECT_ID.'/'.$resource.'?page='.$lastPage,
            'prev' => null,
            'next' => 'https://remote.invalid/api/v1/projects/'.self::OTHER_PROJECT_ID.'/'.$resource.'?page=2',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(int $currentPage, int $lastPage, array $extra = []): array
    {
        return array_merge([
            'current_page' => $currentPage,
            'from' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => 25,
            'to' => $currentPage,
            'total' => 2,
        ], $extra);
    }

    /** @return array<string, mixed> */
    private static function analyticsPayload(): array
    {
        return [
            'window' => [
                'from' => '2026-10-01',
                'to' => '2026-10-10',
                'timezone' => 'UTC',
                'api_key_id' => self::KEY_ID,
                'chat_id' => self::CHAT_ID,
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
            'daily' => [
                self::dailyCounts('2026-10-01'),
                self::dailyCounts('2026-10-02'),
            ],
            'event_types' => [
                'items' => [],
                'other_count' => 0,
                'omitted_group_count' => 0,
            ],
            'tools' => [
                'items' => [],
                'other_count' => 0,
                'omitted_group_count' => 0,
                'missing_name_count' => 0,
            ],
            'failures' => [
                'turn_event_count' => 0,
                'agent_event_count' => 0,
                'tool_event_count' => 0,
                'tool_result_unknown_status_count' => 0,
            ],
            'durations' => [
                'tool_execution' => self::durationDistribution(),
                'turn_execution' => self::durationDistribution(),
            ],
            'file_reads' => array_map(static fn (string $name): array => ['tool_name' => $name, 'count' => 0], [
                'read',
                'wiki_read',
                'skill_read',
                'wiki_read_source',
                'wiki_read_artifact',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function dailyCounts(string $date): array
    {
        return [
            'date' => $date,
            'event_count' => 0,
            'tool_start_count' => 0,
            'file_read_start_count' => 0,
            'turn_failure_count' => 0,
            'agent_failure_count' => 0,
            'tool_failure_count' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private static function durationDistribution(): array
    {
        $bounds = [0, 100, 500, 1000, 2000, 5000, 10000, 30000];

        return [
            'candidate_count' => 0,
            'sample_count' => 0,
            'missing_count' => 0,
            'invalid_count' => 0,
            'zero_count' => 0,
            'min_ms' => null,
            'max_ms' => null,
            'avg_ms' => null,
            'p50_ms' => null,
            'p95_ms' => null,
            'buckets' => array_map(static fn (int $index): array => [
                'lower_ms' => $bounds[$index],
                'upper_ms' => $bounds[$index + 1] ?? null,
                'count' => 0,
            ], array_keys($bounds)),
        ];
    }

    /** @return array<string, mixed> */
    private static function catalogPayload(): array
    {
        return ['data' => ['types' => ['agent.started', 'turn.completed']]];
    }

    /** @return array<string, mixed> */
    private static function errorPayload(string $type): array
    {
        return ['error' => [
            'type' => $type,
            'message' => 'Project report request failed.',
            'details' => null,
        ]];
    }
}

final class RawReportFilter implements ReportFilter
{
    /** @param array<string, mixed> $query */
    public function __construct(private readonly array $query) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->query;
    }
}
