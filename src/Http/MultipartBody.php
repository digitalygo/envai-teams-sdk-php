<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

/**
 * An ordered multipart/form-data body composed of fields and files.
 *
 * Encoding always uses CRLF line endings per RFC 2046. The boundary is
 * generated on construction unless provided explicitly, which makes tests
 * deterministic.
 */
final readonly class MultipartBody
{
    public string $boundary;

    /**
     * @param  list<MultipartField|MultipartFile>  $parts
     */
    public function __construct(
        public array $parts = [],
        ?string $boundary = null,
    ) {
        $this->boundary = $boundary ?? self::generateBoundary();
    }

    /**
     * Generates a random boundary in the conventional dash plus hex form.
     */
    public static function generateBoundary(): string
    {
        return '----------------------------'.bin2hex(random_bytes(16));
    }

    /**
     * Returns the Content-Type header value for this body.
     */
    public function contentType(): string
    {
        return 'multipart/form-data; boundary='.$this->boundary;
    }

    /**
     * Encodes the full body into a string. File parts are read eagerly; the
     * transport may stream large files from MultipartFile::path() instead.
     */
    public function encode(): string
    {
        $body = '';

        foreach ($this->parts as $part) {
            $body .= '--'.$this->boundary."\r\n";

            if ($part instanceof MultipartField) {
                $body .= 'Content-Disposition: form-data; name="'.$part->name."\"\r\n\r\n";
                $body .= $part->value."\r\n";
            } else {
                $body .= 'Content-Disposition: form-data; name="'.$part->name.'"; filename="'.$part->filename."\"\r\n";
                $body .= 'Content-Type: '.$part->mimeType."\r\n\r\n";
                $body .= $part->contents()."\r\n";
            }
        }

        $body .= '--'.$this->boundary."--\r\n";

        return $body;
    }
}
