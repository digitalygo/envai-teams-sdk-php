<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

final readonly class UsageFilters implements ReportFilter
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?string $apiKeyId = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
        $this->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $query = [];

        if ($this->from !== null) {
            $query['from'] = $this->from;
        }

        if ($this->to !== null) {
            $query['to'] = $this->to;
        }

        if ($this->apiKeyId !== null) {
            $query['api_key_id'] = $this->apiKeyId;
        }

        if ($this->page !== null) {
            $query['page'] = $this->page;
        }

        if ($this->perPage !== null) {
            $query['per_page'] = $this->perPage;
        }

        return ReportQuery::prepare($query, ReportQuery::USAGE_KEYS, null);
    }
}
