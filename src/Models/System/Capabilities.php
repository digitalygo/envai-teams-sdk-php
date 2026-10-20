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
 * response. `features` preserves the legacy name list or boolean flag map.
 */
final readonly class Capabilities implements ArrayHydratable
{
    /**
     * @param  list<string>  $families
     * @param  list<string>  $eventTypes
     * @param  list<string>  $toolKinds
     * @param  list<string>|array<string, bool>  $features
     * @param  list<int>  $supportedContractVersions
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public int $protocolVersion,
        public array $families,
        public array $eventTypes,
        public array $toolKinds,
        public array $features,
        public array $extra = [],
        public ?int $contractVersion = null,
        public array $supportedContractVersions = [],
        public ?string $legacyV2Mode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'Capabilities';
        $supported = $data['supported_contract_versions'] ?? [];
        if (! is_array($supported) || ! array_is_list($supported)) {
            throw new \InvalidArgumentException('supported_contract_versions must be a list.');
        }
        $versions = [];
        foreach ($supported as $version) {
            if (! is_int($version)) {
                throw new \InvalidArgumentException('supported_contract_versions must contain integers.');
            }
            $versions[] = $version;
        }

        $features = $data['features'] ?? null;
        if (! is_array($features)) {
            throw new \InvalidArgumentException('Capabilities features must be a list or a boolean map.');
        }
        if (array_is_list($features)) {
            foreach ($features as $feature) {
                if (! is_string($feature)) {
                    throw new \InvalidArgumentException('Capabilities features list must contain strings.');
                }
            }
        } else {
            foreach ($features as $name => $enabled) {
                if (! is_string($name) || ! is_bool($enabled)) {
                    throw new \InvalidArgumentException('Capabilities feature map must contain boolean values.');
                }
            }
        }

        return new self(
            protocolVersion: Wire::int($data, 'protocol_version', $context),
            families: Wire::stringList($data, 'families', $context),
            eventTypes: Wire::stringList($data, 'event_types', $context),
            toolKinds: Wire::stringList($data, 'tool_kinds', $context),
            features: $features,
            extra: Wire::extra($data, ['protocol_version', 'families', 'event_types', 'tool_kinds', 'features', 'contract_version', 'supported_contract_versions', 'legacy_v2_mode']),
            contractVersion: Wire::nullableInt($data, 'contract_version', $context),
            supportedContractVersions: $versions,
            legacyV2Mode: Wire::nullableString($data, 'legacy_v2_mode', $context),
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
        ], $this->extra, $this->contractVersion === null ? [] : ['contract_version' => $this->contractVersion], $this->supportedContractVersions === [] ? [] : ['supported_contract_versions' => $this->supportedContractVersions], $this->legacyV2Mode === null ? [] : ['legacy_v2_mode' => $this->legacyV2Mode]);
    }
}
