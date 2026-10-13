<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class PhaseResult implements ArrayHydratable
{
    /**
     * @param  list<FileOutcome>  $files
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $status,
        public int $selected,
        public int $succeeded,
        public int $withdrawn,
        public int $failed,
        public array $files,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            status: Wire::string($data, 'status', self::class),
            selected: Wire::int($data, 'selected', self::class),
            succeeded: Wire::int($data, 'succeeded', self::class),
            withdrawn: Wire::int($data, 'withdrawn', self::class),
            failed: Wire::int($data, 'failed', self::class),
            files: self::filesFromArray($data),
            extra: Wire::extra($data, ['status', 'selected', 'succeeded', 'withdrawn', 'failed', 'files']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'status' => $this->status,
            'selected' => $this->selected,
            'succeeded' => $this->succeeded,
            'withdrawn' => $this->withdrawn,
            'failed' => $this->failed,
            'files' => array_map(static fn (FileOutcome $item): array => $item->toArray(), $this->files),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<FileOutcome>
     */
    private static function filesFromArray(array $data): array
    {
        $values = Wire::presentOrFail($data, 'files', self::class);
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid files.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Invalid files item.');
            }
            $result[] = FileOutcome::fromArray(Wire::stringKeyed($value, 'item', self::class));
        }

        return $result;
    }
}
