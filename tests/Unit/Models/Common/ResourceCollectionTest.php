<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Models\Common;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use PHPUnit\Framework\TestCase;

/**
 * Minimal ArrayHydratable used by the ResourceCollection tests.
 */
final class HydratableStub implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data = []) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new self($data);
    }
}

final class ResourceCollectionTest extends TestCase
{
    public function test_from_array_hydrates_every_item(): void
    {
        $collection = ResourceCollection::fromArray([
            ['name' => 'one'],
            ['name' => 'two'],
        ], HydratableStub::class);

        $this->assertCount(2, $collection->items);
        $this->assertSame(HydratableStub::class, $collection->itemClass);
        $this->assertInstanceOf(HydratableStub::class, $collection->items[0]);
        $this->assertSame(['name' => 'one'], $collection->items[0]->data);
    }

    public function test_from_array_with_empty_data_returns_empty_collection(): void
    {
        $collection = ResourceCollection::fromArray([], HydratableStub::class);

        $this->assertSame([], $collection->items);
    }

    public function test_from_array_rejects_non_hydratable_item_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResourceCollection::fromArray([], \stdClass::class);
    }

    public function test_from_array_rejects_non_array_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResourceCollection::fromArray(['not-an-array'], HydratableStub::class);
    }
}
