<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Configuration;

/**
 * Immutable client configuration for one Envai connection.
 *
 * The base URL must be the API origin without the canonical /api/v1 prefix:
 * the client appends it. The JWKS URL is derived from the base URL origin
 * when not provided explicitly; the derived value is never stored here.
 */
final readonly class EnvaiConfig
{
    /**
     * @param  array<string, string>  $headers  Additional default request headers.
     * @param  string|null  $definitionsPath  Default path of the local `envai/` definitions directory for the definitions facade; null falls back to `envai`.
     * @param  string|null  $stateDriver  Default sync state store driver for the definitions facade; null falls back to `file`.
     * @param  string|null  $stateFilePath  Default state store file path for the `file` driver; null falls back to `.envai`.
     */
    public function __construct(
        public string $baseUrl,
        public SensitiveString $apiKey,
        public ?string $jwksUrl = null,
        public string $connectionName = 'default',
        public int $connectTimeoutSeconds = 10,
        public int $requestTimeoutSeconds = 60,
        public ?int $streamTimeoutSeconds = null,
        public array $headers = [],
        public ?RetryPolicy $retryPolicy = null,
        public ?string $definitionsPath = null,
        public ?string $stateDriver = null,
        public ?string $stateFilePath = null,
        public int $contractVersion = 3,
        public int $maxLegacyReplayBytes = 67_108_864,
        public bool $nativeTasks = false,
    ) {
        if ($nativeTasks && $contractVersion !== 3) {
            throw new \InvalidArgumentException('nativeTasks requires contract 3.');
        }
        if ($maxLegacyReplayBytes < 1) {
            throw new \InvalidArgumentException('maxLegacyReplayBytes must be positive.');
        }
        if (! in_array($this->contractVersion, [2, 3], true)) {
            throw new \InvalidArgumentException('contractVersion must be 2 or 3.');
        }

        if (self::isHttpUrl($this->baseUrl) === false) {
            throw new \InvalidArgumentException('baseUrl must be a non-empty http(s) URL.');
        }

        if ($this->apiKey->isEmpty()) {
            throw new \InvalidArgumentException('apiKey must not be empty.');
        }

        if ($this->jwksUrl !== null && self::isHttpUrl($this->jwksUrl) === false) {
            throw new \InvalidArgumentException('jwksUrl must be an http(s) URL when provided.');
        }

        if ($this->connectTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be a positive integer.');
        }

        if ($this->requestTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('requestTimeoutSeconds must be a positive integer.');
        }

        if ($this->streamTimeoutSeconds !== null && $this->streamTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('streamTimeoutSeconds must be a positive integer when provided.');
        }

        if ($this->connectionName === '') {
            throw new \InvalidArgumentException('connectionName must not be empty.');
        }
    }

    /**
     * Returns the effective JWKS URL: the explicit one when provided,
     * otherwise the well-known JWKS endpoint derived from the base URL origin.
     */
    public function effectiveJwksUrl(): string
    {
        if ($this->jwksUrl !== null) {
            return $this->jwksUrl;
        }

        $parts = parse_url($this->baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException('baseUrl was already validated in the constructor.');
        }

        $origin = (string) $parts['scheme'].'://'.(string) $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.(string) $parts['port'];
        }

        return $origin.'/.well-known/jwks.json';
    }

    private static function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ($parts['host'] === '') {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}
