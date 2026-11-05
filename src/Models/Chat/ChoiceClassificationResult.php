<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class ChoiceClassificationResult implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $choice,
        public string $model,
        public string $requestId,
        public string $invocationId,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            choice: Wire::string($data, 'choice', 'ChoiceClassificationResult'),
            model: Wire::string($data, 'model', 'ChoiceClassificationResult'),
            requestId: Wire::string($data, 'request_id', 'ChoiceClassificationResult'),
            invocationId: Wire::string($data, 'invocation_id', 'ChoiceClassificationResult'),
            extra: Wire::extra($data, ['choice', 'model', 'request_id', 'invocation_id']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'choice' => $this->choice,
            'model' => $this->model,
            'request_id' => $this->requestId,
            'invocation_id' => $this->invocationId,
        ] + $this->extra;
    }
}
