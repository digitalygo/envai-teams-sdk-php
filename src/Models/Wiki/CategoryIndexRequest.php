<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

final readonly class CategoryIndexRequest
{
    public function __construct(public ?int $from = null, public ?int $to = null, public bool $all = false, public ?string $type = null, public ?int $catalogRevision = null)
    {
        if (($all && ($from !== null || $to !== null)) || (! $all && ($from === null || $to === null || $from < 1 || $to < $from))) {
            throw new \InvalidArgumentException('Provide all=true or an inclusive from/to range starting at 1.');
        }
        if ($type !== null && ! in_array($type, ['raw', 'artifact'], true)) {
            throw new \InvalidArgumentException('type must be raw, artifact or null.');
        }
        if ($catalogRevision !== null && $catalogRevision < 0) {
            throw new \InvalidArgumentException('catalogRevision cannot be negative.');
        }
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'all' => $this->all ? 'true' : null, 'type' => $this->type, 'catalog_revision' => $this->catalogRevision], static fn ($value): bool => $value !== null);
    }
}
