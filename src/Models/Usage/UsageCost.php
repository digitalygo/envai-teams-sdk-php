<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Usage;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class UsageCost implements ArrayHydratable
{
    /**
     * @param  list<CurrencyAmount>|null  $totals
     * @param  list<CurrencyAmount>  $pricedSubtotals
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $status,
        public string $basis,
        public string $scopeStatus,
        public int $revision,
        public string $asOf,
        public int $invocationCount,
        public int $pendingInvocationCount,
        public ?array $totals,
        public array $pricedSubtotals,
        public array $extra = [],
    ) {
        if (! in_array($status, ['pending', 'complete'], true) || ! in_array($basis, ['reported', 'calculated', 'mixed', 'none'], true) || ! in_array($scopeStatus, ['open', 'closed'], true)) {
            throw new \InvalidArgumentException('Invalid usage cost state.');
        }
        if ($revision < 0 || $invocationCount < 0 || $pendingInvocationCount < 0 || $pendingInvocationCount > $invocationCount) {
            throw new \InvalidArgumentException('Invalid usage cost counts.');
        }
        if (($status === 'pending' && $totals !== null) || ($status === 'complete' && ($scopeStatus !== 'closed' || $pendingInvocationCount !== 0 || $totals === null))) {
            throw new \InvalidArgumentException('Usage totals are final only for a complete, closed scope.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            status: Wire::string($data, 'status', self::class),
            basis: Wire::string($data, 'basis', self::class),
            scopeStatus: Wire::string($data, 'scope_status', self::class),
            revision: Wire::int($data, 'revision', self::class),
            asOf: Wire::string($data, 'as_of', self::class),
            invocationCount: Wire::int($data, 'invocation_count', self::class),
            pendingInvocationCount: Wire::int($data, 'pending_invocation_count', self::class),
            totals: self::totalsFromArray($data),
            pricedSubtotals: self::pricedSubtotalsFromArray($data),
            extra: Wire::extra($data, ['status', 'basis', 'scope_status', 'revision', 'as_of', 'invocation_count', 'pending_invocation_count', 'totals', 'priced_subtotals']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'status' => $this->status,
            'basis' => $this->basis,
            'scope_status' => $this->scopeStatus,
            'revision' => $this->revision,
            'as_of' => $this->asOf,
            'invocation_count' => $this->invocationCount,
            'pending_invocation_count' => $this->pendingInvocationCount,
            'totals' => $this->totals === null ? null : array_map(static fn (CurrencyAmount $item): array => $item->toArray(), $this->totals),
            'priced_subtotals' => array_map(static fn (CurrencyAmount $item): array => $item->toArray(), $this->pricedSubtotals),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<CurrencyAmount>|null
     */
    private static function totalsFromArray(array $data): ?array
    {
        if (($data['totals'] ?? null) === null) {
            return null;
        }
        $values = Wire::presentOrFail($data, 'totals', self::class);
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid totals.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Invalid totals item.');
            }
            $result[] = CurrencyAmount::fromArray(Wire::stringKeyed($value, 'item', self::class));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<CurrencyAmount>
     */
    private static function pricedSubtotalsFromArray(array $data): array
    {
        $values = Wire::presentOrFail($data, 'priced_subtotals', self::class);
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid priced_subtotals.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Invalid priced_subtotals item.');
            }
            $result[] = CurrencyAmount::fromArray(Wire::stringKeyed($value, 'item', self::class));
        }

        return $result;
    }
}
