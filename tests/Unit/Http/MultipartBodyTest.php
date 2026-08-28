<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Http;

use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use PHPUnit\Framework\TestCase;

final class MultipartBodyTest extends TestCase
{
    public function test_generated_boundary_has_expected_format(): void
    {
        $boundary = MultipartBody::generateBoundary();

        $this->assertMatchesRegularExpression('/^----------------------------[0-9a-f]{32}$/', $boundary);
    }

    public function test_field_order_is_preserved(): void
    {
        $body = new MultipartBody([
            new MultipartField('first', 'one'),
            new MultipartField('second', 'two'),
        ], 'boundary-123');

        $encoded = $body->encode();

        $this->assertLessThan(
            strpos($encoded, 'name="second"'),
            strpos($encoded, 'name="first"'),
        );
    }

    public function test_encode_includes_fields_and_content_type(): void
    {
        $body = new MultipartBody([
            new MultipartField('name', 'researcher'),
        ], 'boundary-123');

        $encoded = $body->encode();

        $this->assertStringContainsString('--boundary-123', $encoded);
        $this->assertStringContainsString('Content-Disposition: form-data; name="name"', $encoded);
        $this->assertStringContainsString("\r\n\r\nresearcher\r\n", $encoded);
        $this->assertStringEndsWith('--boundary-123--'."\r\n", $encoded);
    }

    public function test_encode_includes_file_part_with_filename_and_mime(): void
    {
        $body = new MultipartBody([
            new MultipartField('caption', 'photo'),
            MultipartFile::fromContents('upload', 'binary-data', 'photo.png', 'image/png'),
        ], 'boundary-123');

        $encoded = $body->encode();

        $this->assertStringContainsString('Content-Disposition: form-data; name="upload"; filename="photo.png"', $encoded);
        $this->assertStringContainsString('Content-Type: image/png', $encoded);
        $this->assertStringContainsString("\r\n\r\nbinary-data\r\n", $encoded);
    }

    public function test_content_type_header_carries_the_boundary(): void
    {
        $body = new MultipartBody([], 'boundary-123');

        $this->assertSame('multipart/form-data; boundary=boundary-123', $body->contentType());
    }

    public function test_file_part_from_path_reads_contents_lazily(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atp-multipart-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'file-from-disk');

        try {
            $part = MultipartFile::fromPath('disk', $path, 'disk.txt', 'text/plain');

            $this->assertSame('file-from-disk', $part->contents());
            $this->assertSame('disk.txt', $part->filename);
        } finally {
            @unlink($path);
        }
    }

    public function test_encode_preserves_interleaved_field_and_file_order(): void
    {
        $body = new MultipartBody([
            new MultipartField('caption', 'photo'),
            MultipartFile::fromContents('upload', 'data', 'photo.png', 'image/png'),
            new MultipartField('after', 'x'),
        ], 'boundary-123');

        $encoded = $body->encode();

        $caption = strpos($encoded, 'name="caption"');
        $upload = strpos($encoded, 'name="upload"');
        $after = strpos($encoded, 'name="after"');

        $this->assertNotFalse($caption);
        $this->assertNotFalse($upload);
        $this->assertNotFalse($after);
        $this->assertTrue($caption < $upload && $upload < $after);
    }
}
