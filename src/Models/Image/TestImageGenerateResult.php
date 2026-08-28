<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Echoed test generation payload: the received prompt and model code plus
 * the metadata of every received file. `received_at` stays an ISO 8601
 * string (wire-faithful).
 */
final readonly class TestImageGenerateResult implements ArrayHydratable
{
    /**
     * @param  list<TestImageFileMetadata>  $files
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $status,
        public string $prompt,
        public string $modelCode,
        public int $filesCount,
        public array $files,
        public string $receivedAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'TestImageGenerateResult';
        $rawFiles = Wire::presentOrFail($data, 'files', $context);

        if (! is_array($rawFiles)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "files" must be an array, got %s.',
                $context,
                get_debug_type($rawFiles),
            ));
        }

        $files = [];

        foreach ($rawFiles as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "files" must be a list of file metadata objects, got a non-array entry.',
                    $context,
                ));
            }

            $files[] = TestImageFileMetadata::fromArray(Wire::stringKeyed($item, 'files', $context));
        }

        return new self(
            status: Wire::string($data, 'status', $context),
            prompt: Wire::string($data, 'prompt', $context),
            modelCode: Wire::string($data, 'model_code', $context),
            filesCount: Wire::int($data, 'files_count', $context),
            files: $files,
            receivedAt: Wire::string($data, 'received_at', $context),
            extra: Wire::extra($data, ['status', 'prompt', 'model_code', 'files_count', 'files', 'received_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'status' => $this->status,
            'prompt' => $this->prompt,
            'model_code' => $this->modelCode,
            'files_count' => $this->filesCount,
            'files' => array_map(static fn (TestImageFileMetadata $file): array => $file->toArray(), $this->files),
            'received_at' => $this->receivedAt,
        ], $this->extra);
    }
}
