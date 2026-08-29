<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookRequest
{
    /** @var array<string, list<string>> */
    public array $headers;

    public WebhookRequestMetadata $metadata;

    /**
     * @param  array<string|int, mixed>  $headers
     * @param  array<string|int, mixed>|null  $metadata
     */
    public function __construct(
        public string $method,
        public string $uri,
        array $headers = [],
        public string $rawBody = '',
        WebhookRequestMetadata|array|null $metadata = null,
    ) {
        if ($method === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $method) !== 1) {
            throw new \InvalidArgumentException('Webhook method must be a non-empty HTTP token.');
        }

        if ($uri === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $uri) === 1) {
            throw new \InvalidArgumentException('Webhook URI must be a non-empty URI without control characters.');
        }

        $this->headers = self::normalizeHeaders($headers);
        $this->metadata = $metadata instanceof WebhookRequestMetadata
            ? $metadata
            : WebhookRequestMetadata::fromArray($metadata ?? []);
    }

    public function header(string $name): ?string
    {
        return $this->headerValues($name)[0] ?? null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        $normalized = strtolower($name);

        return $this->headers[$normalized] ?? [];
    }

    public function hasHeader(string $name): bool
    {
        return $this->headerValues($name) !== [];
    }

    public function body(): string
    {
        return $this->rawBody;
    }

    /**
     * @param  array<string|int, mixed>  $headers
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name) || $name === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Webhook header names must be valid HTTP tokens.');
            }

            $key = strtolower($name);
            $items = is_array($values) ? $values : [$values];

            if ($items === [] || ! array_is_list($items)) {
                throw new \InvalidArgumentException(sprintf('Webhook header "%s" must contain one or more values.', $name));
            }

            foreach ($items as $value) {
                if (! is_string($value)) {
                    throw new \InvalidArgumentException(sprintf('Webhook header "%s" values must be strings.', $name));
                }

                if (preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                    throw new \InvalidArgumentException(sprintf('Webhook header "%s" contains unsafe control characters.', $name));
                }

                $normalized[$key][] = $value;
            }
        }

        return $normalized;
    }
}
