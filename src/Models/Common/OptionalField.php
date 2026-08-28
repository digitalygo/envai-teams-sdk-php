<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Common;

/**
 * Sentinel that distinguishes an absent field from an explicitly null or
 * valued field while building requests.
 */
final readonly class OptionalField
{
    private function __construct(
        private mixed $value,
        private bool $present,
    ) {}

    public static function absent(): self
    {
        return new self(null, false);
    }

    public static function of(mixed $value): self
    {
        return new self($value, true);
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function isAbsent(): bool
    {
        return ! $this->present;
    }

    /**
     * Returns the wrapped value; throws when the field is absent.
     */
    public function value(): mixed
    {
        if (! $this->present) {
            throw new \LogicException('Cannot read the value of an absent optional field.');
        }

        return $this->value;
    }

    /**
     * Returns the wrapped value or null when absent.
     */
    public function orNull(): mixed
    {
        return $this->present ? $this->value : null;
    }
}
