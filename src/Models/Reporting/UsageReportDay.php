<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonArray;
use InvalidArgumentException;
use JsonSerializable;

final readonly class UsageReportDay implements ArrayHydratable, JsonSerializable
{
    /**
     * @param  list<UsageMoney>  $totals
     * @param  list<UsageKeyBreakdown>  $byApiKey
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $date,
        public array $totals,
        public int $invocationCount,
        public int $unknownInvocations,
        public int $notSubmittedInvocations,
        public int $completionCount,
        public array $byApiKey,
        public UsageMediaTotals $media,
        public ?JsonArray $byProject,
        public array $extra = [],
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('Usage report day must use YYYY-MM-DD.');
        }

        foreach ([$invocationCount, $unknownInvocations, $notSubmittedInvocations, $completionCount] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Usage report day counts must not be negative.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            date: Wire::string($data, 'date', 'UsageReportDay'),
            totals: ReportingWire::modelList($data, 'totals', 'UsageReportDay', UsageMoney::class),
            invocationCount: Wire::int($data, 'invocation_count', 'UsageReportDay'),
            unknownInvocations: Wire::int($data, 'unknown_invocations', 'UsageReportDay'),
            notSubmittedInvocations: Wire::int($data, 'not_submitted_invocations', 'UsageReportDay'),
            completionCount: Wire::int($data, 'completion_count', 'UsageReportDay'),
            byApiKey: ReportingWire::modelList($data, 'by_api_key', 'UsageReportDay', UsageKeyBreakdown::class),
            media: UsageMediaTotals::fromArray(ReportingWire::objectArray($data, 'media', 'UsageReportDay')),
            byProject: ReportingWire::requiredNullableJsonArray($data, 'by_project', 'UsageReportDay'),
            extra: Wire::extra($data, ['date', 'totals', 'invocation_count', 'unknown_invocations', 'not_submitted_invocations', 'completion_count', 'by_api_key', 'media', 'by_project']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'date' => $this->date,
            'totals' => array_map(static fn (UsageMoney $money): array => $money->toArray(), $this->totals),
            'invocation_count' => $this->invocationCount,
            'unknown_invocations' => $this->unknownInvocations,
            'not_submitted_invocations' => $this->notSubmittedInvocations,
            'completion_count' => $this->completionCount,
            'by_api_key' => array_map(static fn (UsageKeyBreakdown $breakdown): array => $breakdown->toArray(), $this->byApiKey),
            'media' => $this->media->toArray(),
            'by_project' => $this->byProject?->toArray(),
        ], $this->extra);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        if ($this->byProject !== null) {
            $data['by_project'] = $this->byProject->jsonSerialize();
        }

        return $data;
    }
}
