<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

final readonly class AnalyticsFilters implements ReportFilter
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?string $apiKeyId = null,
        public ?string $chatId = null,
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

        if ($this->chatId !== null) {
            $query['chat_id'] = $this->chatId;
        }

        return ReportQuery::prepare($query, ReportQuery::ANALYTICS_KEYS, null);
    }
}
