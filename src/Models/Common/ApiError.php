<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Common;

/**
 * The {error: {type, code?, message, details?}} envelope returned by the
 * Envai API on error responses.
 */
final readonly class ApiError
{
    /**
     * @param  mixed  $details  Additional error details, for example validation failures.
     * @param  mixed  $extra  Unknown envelope fields preserved for forward compatibility.
     */
    public function __construct(
        public string $type,
        public ?string $code = null,
        public string $message = '',
        public mixed $details = null,
        public mixed $extra = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? 'unknown';
        $code = $data['code'] ?? null;
        $message = $data['message'] ?? '';
        $details = $data['details'] ?? null;

        $extra = array_diff_key($data, array_flip(['type', 'code', 'message', 'details']));

        return new self(
            is_string($type) ? $type : 'unknown',
            is_string($code) ? $code : null,
            is_string($message) ? $message : '',
            $details,
            $extra === [] ? null : $extra,
        );
    }
}
