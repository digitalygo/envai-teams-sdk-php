<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookRequestMetadata
{
    public function __construct(
        public ?string $remoteAddress = null,
        public ?string $serverProtocol = null,
        public ?string $serverName = null,
        public ?int $serverPort = null,
    ) {
        self::assertSafeString($remoteAddress, 'remoteAddress');
        self::assertSafeString($serverProtocol, 'serverProtocol');
        self::assertSafeString($serverName, 'serverName');

        if ($serverPort !== null && ($serverPort < 1 || $serverPort > 65535)) {
            throw new \InvalidArgumentException('serverPort must be between 1 and 65535.');
        }
    }

    /** @param array<string|int, mixed> $metadata */
    public static function fromArray(array $metadata): self
    {
        $remoteAddress = null;
        $serverProtocol = null;
        $serverName = null;
        $serverPort = null;

        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Webhook request metadata keys must be strings.');
            }

            $normalized = strtolower($key);

            if (in_array($normalized, ['remote_address', 'remote_addr', 'remoteaddress', 'client_ip', 'clientip'], true)) {
                if (! is_string($value) && $value !== null) {
                    throw new \InvalidArgumentException(sprintf('Webhook request metadata "%s" must be a string.', $key));
                }

                $remoteAddress = $value;

                continue;
            }

            if (in_array($normalized, ['server_protocol', 'serverprotocol', 'protocol'], true)) {
                if (! is_string($value) && $value !== null) {
                    throw new \InvalidArgumentException(sprintf('Webhook request metadata "%s" must be a string.', $key));
                }

                $serverProtocol = $value;

                continue;
            }

            if (in_array($normalized, ['server_name', 'servername'], true)) {
                if (! is_string($value) && $value !== null) {
                    throw new \InvalidArgumentException(sprintf('Webhook request metadata "%s" must be a string.', $key));
                }

                $serverName = $value;

                continue;
            }

            if (in_array($normalized, ['server_port', 'serverport'], true)) {
                if (! is_int($value) && $value !== null) {
                    throw new \InvalidArgumentException(sprintf('Webhook request metadata "%s" must be an integer.', $key));
                }

                $serverPort = $value;

                continue;
            }

            throw new \InvalidArgumentException(sprintf('Webhook request metadata key "%s" is not allowed.', $key));
        }

        return new self($remoteAddress, $serverProtocol, $serverName, $serverPort);
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        $metadata = [];

        if ($this->remoteAddress !== null) {
            $metadata['remote_address'] = $this->remoteAddress;
        }

        if ($this->serverProtocol !== null) {
            $metadata['server_protocol'] = $this->serverProtocol;
        }

        if ($this->serverName !== null) {
            $metadata['server_name'] = $this->serverName;
        }

        if ($this->serverPort !== null) {
            $metadata['server_port'] = $this->serverPort;
        }

        return $metadata;
    }

    private static function assertSafeString(?string $value, string $name): void
    {
        if ($value !== null && preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('%s contains unsafe control characters.', $name));
        }
    }
}
