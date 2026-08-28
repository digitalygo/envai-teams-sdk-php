<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\System;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Event families and optional protocol features advertised by the server.
 *
 * `event_types` is a positive discovery list rather than a parser allowlist:
 * clients remain forward compatible with valid ATP events absent from this
 * response, and every list is preserved as received.
 */
final readonly class Capabilities implements ArrayHydratable
{
    /**
     * @param  list<string>  $families
     * @param  list<string>  $eventTypes
     * @param  list<string>  $toolKinds
     * @param  list<string>  $features
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public int $protocolVersion,
        public array $families,
        public array $eventTypes,
        public array $toolKinds,
        public array $features,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'Capabilities';

        return new self(
            protocolVersion: Wire::int($data, 'protocol_version', $context),
            families: Wire::stringList($data, 'families', $context),
            eventTypes: Wire::stringList($data, 'event_types', $context),
            toolKinds: Wire::stringList($data, 'tool_kinds', $context),
            features: Wire::stringList($data, 'features', $context),
            extra: Wire::extra($data, ['protocol_version', 'families', 'event_types', 'tool_kinds', 'features']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'protocol_version' => $this->protocolVersion,
            'families' => $this->families,
            'event_types' => $this->eventTypes,
            'tool_kinds' => $this->toolKinds,
            'features' => $this->features,
        ], $this->extra);
    }
}
