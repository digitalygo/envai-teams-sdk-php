<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use JsonSerializable;

final readonly class ProjectActivityAnalytics implements ArrayHydratable, JsonSerializable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public JsonObject $window,
        public JsonObject $coverage,
        public JsonArray $daily,
        public JsonObject $eventTypes,
        public JsonObject $tools,
        public JsonObject $failures,
        public JsonObject $durations,
        public JsonArray $fileReads,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $context = 'ProjectActivityAnalytics';

        return new self(
            window: ReportingWire::jsonObject($data, 'window', $context),
            coverage: ReportingWire::jsonObject($data, 'coverage', $context),
            daily: ReportingWire::jsonArray($data, 'daily', $context),
            eventTypes: ReportingWire::jsonObject($data, 'event_types', $context),
            tools: ReportingWire::jsonObject($data, 'tools', $context),
            failures: ReportingWire::jsonObject($data, 'failures', $context),
            durations: ReportingWire::jsonObject($data, 'durations', $context),
            fileReads: ReportingWire::jsonArray($data, 'file_reads', $context),
            extra: Wire::extra($data, ['window', 'coverage', 'daily', 'event_types', 'tools', 'failures', 'durations', 'file_reads']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'window' => $this->window->toArray(),
            'coverage' => $this->coverage->toArray(),
            'daily' => $this->daily->toArray(),
            'event_types' => $this->eventTypes->toArray(),
            'tools' => $this->tools->toArray(),
            'failures' => $this->failures->toArray(),
            'durations' => $this->durations->toArray(),
            'file_reads' => $this->fileReads->toArray(),
        ], $this->extra);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['window'] = $this->window->jsonSerialize();
        $data['coverage'] = $this->coverage->jsonSerialize();
        $data['event_types'] = $this->eventTypes->jsonSerialize();
        $data['tools'] = $this->tools->jsonSerialize();
        $data['failures'] = $this->failures->jsonSerialize();
        $data['durations'] = $this->durations->jsonSerialize();
        $data['daily'] = $this->daily->jsonSerialize();
        $data['file_reads'] = $this->fileReads->jsonSerialize();

        return $data;
    }
}
