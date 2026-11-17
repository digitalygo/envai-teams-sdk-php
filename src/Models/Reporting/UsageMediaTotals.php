<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use InvalidArgumentException;

final readonly class UsageMediaTotals implements ArrayHydratable
{
    /**
     * @param  list<UsageMoney>  $totals
     * @param  list<UsageKeyBreakdown>  $byApiKey
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public array $totals,
        public int $pricedObservations,
        public int $unknownObservations,
        public int $unknownCurrencyObservations,
        public array $byApiKey,
        public array $extra = [],
    ) {
        foreach ([$pricedObservations, $unknownObservations, $unknownCurrencyObservations] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Usage media counts must not be negative.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            totals: ReportingWire::modelList($data, 'totals', 'UsageMediaTotals', UsageMoney::class),
            pricedObservations: Wire::int($data, 'priced_observations', 'UsageMediaTotals'),
            unknownObservations: Wire::int($data, 'unknown_observations', 'UsageMediaTotals'),
            unknownCurrencyObservations: Wire::int($data, 'unknown_currency_observations', 'UsageMediaTotals'),
            byApiKey: ReportingWire::modelList($data, 'by_api_key', 'UsageMediaTotals', UsageKeyBreakdown::class),
            extra: Wire::extra($data, ['totals', 'priced_observations', 'unknown_observations', 'unknown_currency_observations', 'by_api_key']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'totals' => array_map(static fn (UsageMoney $money): array => $money->toArray(), $this->totals),
            'priced_observations' => $this->pricedObservations,
            'unknown_observations' => $this->unknownObservations,
            'unknown_currency_observations' => $this->unknownCurrencyObservations,
            'by_api_key' => array_map(static fn (UsageKeyBreakdown $breakdown): array => $breakdown->toArray(), $this->byApiKey),
        ], $this->extra);
    }
}
