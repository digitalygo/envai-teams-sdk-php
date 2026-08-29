<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookVerificationMetadata implements \JsonSerializable
{
    /** @var array<string, string|int|float|bool|null> */
    private array $values;

    /** @param array<string, string|int|float|bool|null> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $values
     * @param  list<string>  $allowlistedKeys
     */
    public static function fromAllowlist(array $values, array $allowlistedKeys): self
    {
        $allowlist = [];

        foreach ($allowlistedKeys as $key) {
            if ($key === '' || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1) {
                throw new WebhookVerificationException('Webhook verification metadata allowlist contains an invalid key.');
            }

            if (self::isSensitiveKey($key)) {
                throw new WebhookVerificationException('Webhook verification metadata allowlist contains a sensitive key.');
            }

            $allowlist[$key] = true;
        }

        $result = [];

        foreach ($values as $key => $value) {
            if (! isset($allowlist[$key])) {
                throw new WebhookVerificationException('Webhook verification metadata contains a key outside its allowlist.');
            }

            if (is_string($value) && preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1) {
                throw new WebhookVerificationException(sprintf('Webhook verification metadata "%s" contains unsafe control characters.', $key));
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new WebhookVerificationException(sprintf('Webhook verification metadata "%s" contains a non-finite number.', $key));
            }

            $result[$key] = $value;
        }

        return new self($result);
    }

    public function get(string $key, string|int|float|bool|null $default = null): string|int|float|bool|null
    {
        if (! array_key_exists($key, $this->values)) {
            return $default;
        }

        return $this->values[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string, string|int|float|bool|null> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @return array<string, string|int|float|bool|null> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return preg_match('/(?:authorization|cookie|credential|header|password|raw[_-]?body|secret|signature|token)/i', $key) === 1
            || preg_match('/(?:^|[_-])api[_-]?key(?:$|[_-])/i', $key) === 1;
    }
}
