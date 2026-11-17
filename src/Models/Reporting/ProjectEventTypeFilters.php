<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use InvalidArgumentException;

final readonly class ProjectEventTypeFilters implements ArrayHydratable
{
    /** @var list<string> */
    public array $types;

    /** @var array<string, mixed> */
    public array $extra;

    /**
     * @param  array<array-key, mixed>  $types
     * @param  array<string, mixed>  $extra
     */
    public function __construct(array $types, array $extra = [])
    {
        if (count($types) > 1000) {
            throw new InvalidArgumentException('Project event type catalog exceeds 1000 entries.');
        }

        $seen = [];
        $normalized = [];

        foreach ($types as $type) {
            if (! is_string($type) || mb_strlen($type, 'UTF-8') < 1 || mb_strlen($type, 'UTF-8') > 80 || array_key_exists($type, $seen)) {
                throw new InvalidArgumentException('Project event type catalog contains an invalid or duplicate type.');
            }

            $seen[$type] = true;
            $normalized[] = $type;
        }

        $this->types = $normalized;
        $this->extra = $extra;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $types = [];

        foreach (ReportingWire::listArray($data, 'types', 'ProjectEventTypeFilters') as $type) {
            if (! is_string($type)) {
                throw new InvalidArgumentException('Project event type catalog contains a non-string type.');
            }

            $types[] = $type;
        }

        return new self(
            types: $types,
            extra: Wire::extra($data, ['types']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'types' => $this->types,
        ], $this->extra);
    }
}
