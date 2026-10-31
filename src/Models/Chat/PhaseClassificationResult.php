<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class PhaseClassificationResult implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public PhaseDecision $decision,
        public string $model,
        public string $requestId,
        public string $invocationId,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            decision: PhaseDecision::from(Wire::string($data, 'decision', 'PhaseClassificationResult')),
            model: Wire::string($data, 'model', 'PhaseClassificationResult'),
            requestId: Wire::string($data, 'request_id', 'PhaseClassificationResult'),
            invocationId: Wire::string($data, 'invocation_id', 'PhaseClassificationResult'),
            extra: Wire::extra($data, ['decision', 'model', 'request_id', 'invocation_id']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->value,
            'model' => $this->model,
            'request_id' => $this->requestId,
            'invocation_id' => $this->invocationId,
        ] + $this->extra;
    }
}
