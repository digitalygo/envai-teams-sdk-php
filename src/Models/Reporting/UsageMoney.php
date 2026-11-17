<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use InvalidArgumentException;

final readonly class UsageMoney implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $currency,
        public string $amount,
        public array $extra = [],
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $amount) !== 1) {
            throw new InvalidArgumentException('Usage money must use an ISO currency code and an exact decimal string.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            currency: Wire::string($data, 'currency', 'UsageMoney'),
            amount: Wire::string($data, 'amount', 'UsageMoney'),
            extra: Wire::extra($data, ['currency', 'amount']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'currency' => $this->currency,
            'amount' => $this->amount,
        ], $this->extra);
    }
}
