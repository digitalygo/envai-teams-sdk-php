<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Usage;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class CurrencyAmount implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $currency,
        public string $amount,
        public array $extra = [],
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $amount) !== 1) {
            throw new \InvalidArgumentException('Currency and non-negative decimal string amount are required.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            currency: Wire::string($data, 'currency', self::class),
            amount: Wire::string($data, 'amount', self::class),
            extra: Wire::extra($data, ['currency', 'amount']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'currency' => $this->currency,
            'amount' => $this->amount,
        ]);
    }
}
