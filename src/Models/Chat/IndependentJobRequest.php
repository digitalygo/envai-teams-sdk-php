<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

final readonly class IndependentJobRequest
{
    public function __construct(
        public string $workspaceId,
        public string $requestId,
        public string $prompt,
        public ?bool $strictModel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'workspace_id' => $this->workspaceId,
            'request_id' => $this->requestId,
            'prompt' => $this->prompt,
        ];
        if ($this->strictModel !== null) {
            $data['strict_model'] = $this->strictModel;
        }

        return $data;
    }
}
