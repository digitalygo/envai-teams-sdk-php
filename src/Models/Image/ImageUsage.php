<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Usage counters persisted at finalization of an image generation.
 */
final readonly class ImageUsage implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public int $schemaVersion,
        public int $attemptsTotal,
        public int $attemptsPriced,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ImageUsage';

        return new self(
            schemaVersion: Wire::int($data, 'schema_version', $context),
            attemptsTotal: Wire::int($data, 'attempts_total', $context),
            attemptsPriced: Wire::int($data, 'attempts_priced', $context),
            extra: Wire::extra($data, ['schema_version', 'attempts_total', 'attempts_priced']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'schema_version' => $this->schemaVersion,
            'attempts_total' => $this->attemptsTotal,
            'attempts_priced' => $this->attemptsPriced,
        ], $this->extra);
    }
}
