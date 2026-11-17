<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

final readonly class EventLogFilters implements ReportFilter
{
    /**
     * @param  list<string>|null  $types
     * @param  list<string>|null  $workspaceIds
     * @param  list<string>|null  $apiKeyIds
     */
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?string $apiKeyId = null,
        public ?string $chatId = null,
        public ?string $type = null,
        public ?array $types = null,
        public ?array $workspaceIds = null,
        public ?array $apiKeyIds = null,
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

        if ($this->chatId !== null) {
            $query['chat_id'] = $this->chatId;
        }

        if ($this->type !== null) {
            $query['type'] = $this->type;
        }

        if ($this->types !== null) {
            $query['types'] = $this->types;
        }

        if ($this->workspaceIds !== null) {
            $query['workspace_ids'] = $this->workspaceIds;
        }

        if ($this->apiKeyIds !== null) {
            $query['api_key_ids'] = $this->apiKeyIds;
        }

        if ($this->page !== null) {
            $query['page'] = $this->page;
        }

        if ($this->perPage !== null) {
            $query['per_page'] = $this->perPage;
        }

        return ReportQuery::prepare($query, ReportQuery::EVENT_KEYS, null);
    }
}
