<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Image generation lifecycle status record returned by the polling endpoint.
 *
 * The nested `result`, `error` and `files` shapes are provider derived and
 * nullable field by field, so they are preserved as wire-faithful JsonObject
 * values instead of being flattened; `price` and `usage` hydrate into their
 * typed records. Timestamps stay ISO 8601 strings (wire-faithful).
 */
final readonly class ImageGenerationStatusRecord implements ArrayHydratable
{
    /**
     * @param  list<JsonObject>|null  $files
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public ImageGenerationLifecycleStatus $status,
        public string $provider,
        public string $initialProvider,
        public bool $routed,
        public string $model,
        public string $createdAt,
        public ?string $submittedAt = null,
        public ?string $completedAt = null,
        public ?string $expiresAt = null,
        public ?JsonObject $result = null,
        public ?JsonObject $error = null,
        public ?array $files = null,
        public ?ImagePrice $price = null,
        public ?ImageUsage $usage = null,
        public ?float $costUsd = null,
        public ?int $durationMs = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ImageGenerationStatusRecord';

        $files = null;

        if (array_key_exists('files', $data) && $data['files'] !== null) {
            if (! is_array($data['files'])) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "files" must be an array, got %s.',
                    $context,
                    get_debug_type($data['files']),
                ));
            }

            $list = [];

            foreach ($data['files'] as $item) {
                if (! is_array($item)) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s payload field "files" must be a list of file objects, got a non-array entry.',
                        $context,
                    ));
                }

                $list[] = new JsonObject(Wire::stringKeyed($item, 'files', $context));
            }

            $files = $list;
        }

        return new self(
            id: Wire::string($data, 'id', $context),
            status: Wire::enum($data, 'status', ImageGenerationLifecycleStatus::class, $context),
            provider: Wire::string($data, 'provider', $context),
            initialProvider: Wire::string($data, 'initial_provider', $context),
            routed: Wire::bool($data, 'routed', $context),
            model: Wire::string($data, 'model', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            submittedAt: Wire::nullableString($data, 'submitted_at', $context),
            completedAt: Wire::nullableString($data, 'completed_at', $context),
            expiresAt: Wire::nullableString($data, 'expires_at', $context),
            result: Wire::nullableJsonObject($data, 'result', $context),
            error: Wire::nullableJsonObject($data, 'error', $context),
            files: $files,
            price: self::nullableImagePrice($data, $context),
            usage: self::nullableImageUsage($data, $context),
            costUsd: Wire::nullableFloat($data, 'cost_usd', $context),
            durationMs: Wire::nullableInt($data, 'duration_ms', $context),
            extra: Wire::extra($data, [
                'id',
                'status',
                'provider',
                'initial_provider',
                'routed',
                'model',
                'created_at',
                'submitted_at',
                'completed_at',
                'expires_at',
                'result',
                'error',
                'files',
                'price',
                'usage',
                'cost_usd',
                'duration_ms',
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableImagePrice(array $data, string $context): ?ImagePrice
    {
        if (! array_key_exists('price', $data) || $data['price'] === null) {
            return null;
        }

        if (! is_array($data['price'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "price" must be an object, got %s.',
                $context,
                get_debug_type($data['price']),
            ));
        }

        return ImagePrice::fromArray(Wire::stringKeyed($data['price'], 'price', $context));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableImageUsage(array $data, string $context): ?ImageUsage
    {
        if (! array_key_exists('usage', $data) || $data['usage'] === null) {
            return null;
        }

        if (! is_array($data['usage'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "usage" must be an object, got %s.',
                $context,
                get_debug_type($data['usage']),
            ));
        }

        return ImageUsage::fromArray(Wire::stringKeyed($data['usage'], 'usage', $context));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'initial_provider' => $this->initialProvider,
            'routed' => $this->routed,
            'model' => $this->model,
            'created_at' => $this->createdAt,
            'submitted_at' => $this->submittedAt,
            'completed_at' => $this->completedAt,
            'expires_at' => $this->expiresAt,
        ];

        if ($this->result !== null) {
            $payload['result'] = $this->result->toArray();
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error->toArray();
        }

        if ($this->files !== null) {
            $payload['files'] = array_map(static fn (JsonObject $file): array => $file->toArray(), $this->files);
        }

        if ($this->price !== null) {
            $payload['price'] = $this->price->toArray();
        }

        if ($this->usage !== null) {
            $payload['usage'] = $this->usage->toArray();
        }

        if ($this->costUsd !== null) {
            $payload['cost_usd'] = $this->costUsd;
        }

        if ($this->durationMs !== null) {
            $payload['duration_ms'] = $this->durationMs;
        }

        return array_merge($payload, $this->extra);
    }
}
