<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Common;

use Digitalygo\Envai\Models\Internal\Wire;

final readonly class CleanupResult implements ArrayHydratable
{
    /**
     * @param  list<CleanupIntentState>  $cleanup
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public string $resourceType, public string $resourceId, public array $cleanup, public array $extra = []) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $items = $data['cleanup'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new \InvalidArgumentException('CleanupResult payload field "cleanup" must be a list.');
        }
        $cleanup = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException('CleanupResult cleanup entries must be objects.');
            }
            $cleanup[] = CleanupIntentState::fromArray(Wire::stringKeyed($item, 'cleanup', 'CleanupResult'));
        }

        return new self(
            Wire::string($data, 'resource_type', 'CleanupResult'),
            Wire::string($data, 'resource_id', 'CleanupResult'),
            $cleanup,
            Wire::extra($data, ['resource_type', 'resource_id', 'cleanup']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'cleanup' => array_map(static fn (CleanupIntentState $item): array => $item->toArray(), $this->cleanup),
        ], $this->extra);
    }
}
