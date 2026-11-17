<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use InvalidArgumentException;

final readonly class UsageKeyBreakdown implements ArrayHydratable
{
    /**
     * @param  list<UsageMoney>  $totals
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $apiKeyId,
        public array $totals,
        public int $invocationCount,
        public int $unknownInvocations,
        public int $notSubmittedInvocations,
        public int $completionCount,
        public int $unknownCurrencyObservations,
        public array $extra = [],
    ) {
        foreach ([$invocationCount, $unknownInvocations, $notSubmittedInvocations, $completionCount, $unknownCurrencyObservations] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Usage key breakdown counts must not be negative.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            apiKeyId: Wire::string($data, 'api_key_id', 'UsageKeyBreakdown'),
            totals: ReportingWire::modelList($data, 'totals', 'UsageKeyBreakdown', UsageMoney::class),
            invocationCount: Wire::int($data, 'invocation_count', 'UsageKeyBreakdown'),
            unknownInvocations: Wire::int($data, 'unknown_invocations', 'UsageKeyBreakdown'),
            notSubmittedInvocations: Wire::int($data, 'not_submitted_invocations', 'UsageKeyBreakdown'),
            completionCount: Wire::int($data, 'completion_count', 'UsageKeyBreakdown'),
            unknownCurrencyObservations: Wire::int($data, 'unknown_currency_observations', 'UsageKeyBreakdown'),
            extra: Wire::extra($data, ['api_key_id', 'totals', 'invocation_count', 'unknown_invocations', 'not_submitted_invocations', 'completion_count', 'unknown_currency_observations']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'api_key_id' => $this->apiKeyId,
            'totals' => array_map(static fn (UsageMoney $money): array => $money->toArray(), $this->totals),
            'invocation_count' => $this->invocationCount,
            'unknown_invocations' => $this->unknownInvocations,
            'not_submitted_invocations' => $this->notSubmittedInvocations,
            'completion_count' => $this->completionCount,
            'unknown_currency_observations' => $this->unknownCurrencyObservations,
        ], $this->extra);
    }
}
