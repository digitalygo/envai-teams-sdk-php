<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

use Digitalygo\Envai\Exceptions\FileException;

/**
 * A file part of a multipart body, backed either by a filesystem path or by
 * in-memory contents.
 */
final readonly class MultipartFile
{
    private function __construct(
        public string $name,
        public string $filename,
        public string $mimeType,
        public ?string $path = null,
        public ?string $contents = null,
    ) {}

    /**
     * @param  string  $filename  Optional override; defaults to the path basename.
     */
    public static function fromPath(
        string $name,
        string $path,
        string $filename = '',
        string $mimeType = 'application/octet-stream',
    ): self {
        if ($path === '') {
            throw new \InvalidArgumentException('Path must not be empty.');
        }

        return new self($name, $filename === '' ? basename($path) : $filename, $mimeType, path: $path);
    }

    public static function fromContents(
        string $name,
        string $contents,
        string $filename,
        string $mimeType = 'application/octet-stream',
    ): self {
        return new self($name, $filename, $mimeType, contents: $contents);
    }

    /**
     * Returns the file contents, reading from the path lazily when needed.
     */
    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if ($this->path === null) {
            throw new \LogicException('MultipartFile has neither path nor contents.');
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw FileException::local('Unable to read multipart file at "'.$this->path.'".', ['path' => $this->path]);
        }

        return $contents;
    }
}
