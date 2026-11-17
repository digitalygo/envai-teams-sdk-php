<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

final readonly class TypeCatalogFilters implements ReportFilter
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
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

        return ReportQuery::prepare($query, ReportQuery::TYPE_CATALOG_KEYS, null);
    }
}
