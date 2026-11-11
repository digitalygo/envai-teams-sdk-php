<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

final readonly class ChoiceClassificationRequest
{
    /** @param list<array{label: string, description: string}> $choices */
    public function __construct(
        public string $requestId,
        public mixed $context,
        public string $question,
        public array $choices,
    ) {
        if ($context === null || count($choices) < 2 || count($choices) > 32) {
            throw new \InvalidArgumentException('Choice classification requires context and 2 to 32 choices.');
        }
        $labels = [];
        foreach ($choices as $choice) {
            if ($choice['label'] === '' || in_array($choice['label'], $labels, true)) {
                throw new \InvalidArgumentException('Choice labels must be nonempty and unique.');
            }
            $labels[] = $choice['label'];
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'request_id' => $this->requestId,
            'context' => $this->context,
            'question' => $this->question,
            'choices' => $this->choices,
        ];

        return $data;
    }
}
