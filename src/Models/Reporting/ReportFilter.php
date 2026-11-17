<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

interface ReportFilter
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
