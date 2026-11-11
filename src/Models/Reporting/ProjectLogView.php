<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

enum ProjectLogView: string
{
    case Events = 'events';
    case Analytics = 'analytics';
    case Filters = 'filters';
}
