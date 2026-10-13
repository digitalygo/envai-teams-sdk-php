<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

final readonly class WikiRunRequest
{
    public function __construct(public string $webhookUrl)
    {
        if (filter_var($webhookUrl, FILTER_VALIDATE_URL) === false || parse_url($webhookUrl, PHP_URL_SCHEME) !== 'https' || parse_url($webhookUrl, PHP_URL_USER) !== null || parse_url($webhookUrl, PHP_URL_PASS) !== null || parse_url($webhookUrl, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException('webhookUrl must be HTTPS without credentials or fragments.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['webhook_url' => $this->webhookUrl];
    }
}
