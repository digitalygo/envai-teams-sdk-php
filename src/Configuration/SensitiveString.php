<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Configuration;

/**
 * Wraps a secret string so it can never leak through casual output paths.
 *
 * The wrapped value is only accessible through an explicit reveal() call.
 * __toString(), __debugInfo(), jsonSerialize() and var_export() all redact the
 * value, so logging, dumping or encoding the object never embeds credentials.
 *
 * The value lives in a static WeakMap keyed by the instance instead of an
 * instance property: this keeps var_export() free of the secret (it would
 * otherwise dump private properties into the __set_state() literal).
 */
final class SensitiveString implements \JsonSerializable
{
    /**
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $values = null;

    public function __construct(string $value)
    {
        self::$values ??= new \WeakMap;
        self::$values[$this] = $value;
    }

    /**
     * Returns the wrapped secret. Call this explicitly at the boundary where
     * the value is genuinely needed (for example the Authorization header).
     */
    public function reveal(): string
    {
        $value = self::$values[$this] ?? null;

        if (! is_string($value)) {
            throw new \LogicException('SensitiveString value is not available; the instance was not constructed with a value.');
        }

        return $value;
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function jsonSerialize(): string
    {
        return '[redacted]';
    }

    /**
     * The serialized form never contains the secret: serialize() output is
     * safe to store, and a deserialized instance holds the redacted marker.
     * Do not rely on serialize() round-tripping the real value.
     *
     * @return array{value: string}
     */
    public function __serialize(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @param  array{value: string}  $data
     */
    public function __unserialize(array $data): void
    {
        self::$values ??= new \WeakMap;
        self::$values[$this] = $data['value'];
    }

    /**
     * var_export() renders the redacted form only, never the secret.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self('[redacted]');
    }
}
