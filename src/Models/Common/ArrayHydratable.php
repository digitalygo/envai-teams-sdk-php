<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Common;

/**
 * Implemented by DTOs that can be rebuilt from an associative array decoded
 * from JSON. The static return type keeps hydration covariant per class.
 */
interface ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static;
}
