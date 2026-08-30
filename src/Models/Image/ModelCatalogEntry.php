<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Canonical model entry of the image model catalog: the providers able to
 * serve the model, its price classes and the settings exposed by the
 * providers.
 */
final readonly class ModelCatalogEntry implements ArrayHydratable
{
    /**
     * @param  list<string>  $providers
     * @param  list<ModelPriceClass>  $priceClasses
     * @param  list<ModelSetting>  $modelSettings
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $model,
        public array $providers,
        public array $priceClasses,
        public array $modelSettings,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'ModelCatalogEntry';

        return new self(
            model: Wire::string($data, 'model', $context),
            providers: Wire::stringList($data, 'providers', $context),
            priceClasses: self::priceClasses($data, $context),
            modelSettings: self::modelSettings($data, $context),
            extra: Wire::extra($data, ['model', 'providers', 'price_classes', 'model_settings']),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ModelPriceClass>
     */
    private static function priceClasses(array $data, string $context): array
    {
        $raw = Wire::presentOrFail($data, 'price_classes', $context);

        if (! is_array($raw)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "price_classes" must be an array, got %s.',
                $context,
                get_debug_type($raw),
            ));
        }

        $items = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "price_classes" must be a list of price class objects, got a non-array entry.',
                    $context,
                ));
            }

            $items[] = ModelPriceClass::fromArray(Wire::stringKeyed($item, 'price_classes', $context));
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ModelSetting>
     */
    private static function modelSettings(array $data, string $context): array
    {
        $raw = Wire::presentOrFail($data, 'model_settings', $context);

        if (! is_array($raw)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "model_settings" must be an array, got %s.',
                $context,
                get_debug_type($raw),
            ));
        }

        $items = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "model_settings" must be a list of setting objects, got a non-array entry.',
                    $context,
                ));
            }

            $items[] = ModelSetting::fromArray(Wire::stringKeyed($item, 'model_settings', $context));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'model' => $this->model,
            'providers' => $this->providers,
            'price_classes' => array_map(static fn (ModelPriceClass $class): array => $class->toArray(), $this->priceClasses),
            'model_settings' => array_map(static fn (ModelSetting $setting): array => $setting->toArray(), $this->modelSettings),
        ], $this->extra);
    }
}
