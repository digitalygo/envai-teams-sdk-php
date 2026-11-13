<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Models\Reporting\ProjectActivityAnalytics;
use Digitalygo\Envai\Models\Reporting\ProjectEventTypeFilters;
use Digitalygo\Envai\Models\Reporting\ProjectLogEntry;
use Digitalygo\Envai\Models\Reporting\ProjectLogView;
use Digitalygo\Envai\Models\Reporting\ReportFilter;
use Digitalygo\Envai\Models\Reporting\ReportQuery;
use Digitalygo\Envai\Models\Reporting\UsageReportDay;
use Digitalygo\Envai\Pagination\Page;
use InvalidArgumentException;

final readonly class ProjectReportsService
{
    private TypedRequester $requests;

    public function __construct(
        RequestExecutor $executor,
        UriResolver $uris,
        int $connectTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 60,
    ) {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    /** @return Page<UsageReportDay> */
    public function usage(string $projectId, ?ReportFilter $filters = null): Page
    {
        return $this->usagePage($projectId, $this->pageOf($filters), $filters);
    }

    /** @return Page<UsageReportDay> */
    public function usagePage(string $projectId, int $page = 1, ?ReportFilter $filters = null): Page
    {
        $query = $this->withPage($this->query($filters, null, ReportQuery::USAGE_KEYS), $page);
        $payload = $this->requests->payload('GET', $this->path($projectId, 'usage'), $query, null);

        /** @var Page<UsageReportDay> $pageResult */
        $pageResult = Page::fromArray($payload, UsageReportDay::class);

        return $pageResult;
    }

    /** @return \Generator<int, UsageReportDay, mixed, void> */
    public function iterateUsage(string $projectId, ?ReportFilter $filters = null, ?int $startPage = null): \Generator
    {
        $number = $startPage ?? $this->pageOf($filters);

        do {
            $page = $this->usagePage($projectId, $number, $filters);
            if ($page->meta->currentPage !== $number || $page->meta->lastPage < $number) {
                throw new ProtocolViolationException('Usage pagination did not advance as requested.');
            }

            yield from $page->items;
            $number++;
        } while ($number <= $page->meta->lastPage);
    }

    /** @return Page<ProjectLogEntry> */
    public function logs(string $projectId, ?ReportFilter $filters = null): Page
    {
        return $this->logsPage($projectId, $this->pageOf($filters), $filters);
    }

    /** @return Page<ProjectLogEntry> */
    public function logsPage(string $projectId, int $page = 1, ?ReportFilter $filters = null): Page
    {
        $query = $this->withPage($this->query($filters, ProjectLogView::Events, ReportQuery::EVENT_KEYS), $page);
        $payload = $this->requests->payload('GET', $this->path($projectId, 'logs'), $query, null);

        /** @var Page<ProjectLogEntry> $pageResult */
        $pageResult = Page::fromArray($payload, ProjectLogEntry::class);

        return $pageResult;
    }

    /** @return \Generator<int, ProjectLogEntry, mixed, void> */
    public function iterateLogs(string $projectId, ?ReportFilter $filters = null, ?int $startPage = null): \Generator
    {
        $number = $startPage ?? $this->pageOf($filters);

        do {
            $page = $this->logsPage($projectId, $number, $filters);
            if ($page->meta->currentPage !== $number || $page->meta->lastPage < $number) {
                throw new ProtocolViolationException('Project log pagination did not advance as requested.');
            }

            yield from $page->items;
            $number++;
        } while ($number <= $page->meta->lastPage);
    }

    public function analytics(string $projectId, ?ReportFilter $filters = null): ProjectActivityAnalytics
    {
        $query = $this->query($filters, ProjectLogView::Analytics, ReportQuery::ANALYTICS_KEYS);
        $result = $this->requests->request(
            'GET',
            $this->path($projectId, 'logs'),
            $query,
            null,
            ProjectActivityAnalytics::class,
            EnvelopeMode::Envelope,
        );

        /** @var ProjectActivityAnalytics $result */
        return $result;
    }

    public function filters(string $projectId, ?ReportFilter $filters = null): ProjectEventTypeFilters
    {
        $query = $this->query($filters, ProjectLogView::Filters, ReportQuery::TYPE_CATALOG_KEYS);
        $result = $this->requests->request(
            'GET',
            $this->path($projectId, 'logs'),
            $query,
            null,
            ProjectEventTypeFilters::class,
            EnvelopeMode::Envelope,
        );

        /** @var ProjectEventTypeFilters $result */
        return $result;
    }

    public function eventTypes(string $projectId, ?ReportFilter $filters = null): ProjectEventTypeFilters
    {
        return $this->filters($projectId, $filters);
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function query(?ReportFilter $filters, ?ProjectLogView $view, array $allowed): array
    {
        return ReportQuery::prepare($filters?->toArray() ?? [], $allowed, $view);
    }

    private function pageOf(?ReportFilter $filters): int
    {
        $query = $filters?->toArray() ?? [];

        if (! array_key_exists('page', $query)) {
            return 1;
        }

        if (! is_int($query['page'])) {
            throw new InvalidArgumentException('page must be a positive integer.');
        }

        return $query['page'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withPage(array $query, int $page): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be positive.');
        }

        if ($page > 1 || array_key_exists('page', $query)) {
            $query['page'] = $page;
        }

        return $query;
    }

    private function path(string $projectId, string $resource): string
    {
        return $this->requests->resolve('/projects/{project_id}/'.$resource, ['project_id' => $projectId]);
    }
}
