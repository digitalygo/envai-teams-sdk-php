<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookValidator
{
    public const DEFAULT_MAX_BODY_BYTES = 1048576;

    public function __construct(public int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES)
    {
        if ($maxBodyBytes < 1) {
            throw new \InvalidArgumentException('maxBodyBytes must be positive.');
        }
    }

    public function validate(WebhookRequest $request): WebhookPayload
    {
        $bodyLength = strlen($request->rawBody);

        if ($bodyLength > $this->maxBodyBytes) {
            throw new WebhookValidationException('Webhook body exceeds the configured size limit.', ['body_bytes' => $bodyLength]);
        }

        $contentTypes = $request->headerValues('content-type');

        if (count($contentTypes) !== 1 || ! self::isJsonMediaType($contentTypes[0])) {
            throw new WebhookValidationException('Webhook content type must be JSON.');
        }

        try {
            $decoded = json_decode($request->rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookValidationException('Webhook body is not valid JSON.', [], $exception);
        }

        if (! $decoded instanceof \stdClass) {
            $rootType = is_array($decoded) ? 'list' : get_debug_type($decoded);

            throw new WebhookValidationException(sprintf('Webhook JSON root must be an object, got %s.', $rootType), ['root_type' => $rootType]);
        }

        return WebhookPayload::fromDecoded($decoded, $request->rawBody);
    }

    private static function isJsonMediaType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mediaType === 'application/json') {
            return true;
        }

        return str_starts_with($mediaType, 'application/') && str_ends_with($mediaType, '+json');
    }
}
