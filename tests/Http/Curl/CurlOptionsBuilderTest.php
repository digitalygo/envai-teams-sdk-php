<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Curl;

use Digitalygo\Envai\Http\Curl\CurlOptionsBuilder;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\StreamRequest;
use PHPUnit\Framework\TestCase;

final class CurlOptionsBuilderTest extends TestCase
{
    public function test_for_request_sets_method_and_timeouts(): void
    {
        $options = CurlOptionsBuilder::forRequest(HttpRequest::get('https://api.example/up'));

        $this->assertSame('GET', $options[CURLOPT_CUSTOMREQUEST]);
        $this->assertSame(10000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertSame(60000, $options[CURLOPT_TIMEOUT_MS]);
        $this->assertTrue($options[CURLOPT_NOSIGNAL]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertFalse($options[CURLOPT_RETURNTRANSFER]);
    }

    public function test_for_request_uses_custom_method_for_delete_without_body(): void
    {
        $options = CurlOptionsBuilder::forRequest(HttpRequest::delete('https://api.example/tools/x'));

        $this->assertSame('DELETE', $options[CURLOPT_CUSTOMREQUEST]);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
    }

    public function test_for_request_string_body_is_passed_as_is_with_headers(): void
    {
        $options = CurlOptionsBuilder::forRequest(
            HttpRequest::post('https://api.example/chats', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer secret'], '{"a":1}'),
        );

        $this->assertSame('{"a":1}', $options[CURLOPT_POSTFIELDS]);
        $this->assertContains('content-type: application/json', $options[CURLOPT_HTTPHEADER]);
        $this->assertContains('authorization: Bearer secret', $options[CURLOPT_HTTPHEADER]);
        $this->assertContains('Expect:', $options[CURLOPT_HTTPHEADER]);
    }

    public function test_for_request_multipart_uses_postfields_array_and_drops_content_type(): void
    {
        $body = new MultipartBody([
            new MultipartField('caption', 'photo'),
            MultipartFile::fromContents('upload', 'binary-data', 'photo.png', 'image/png'),
        ]);

        $options = CurlOptionsBuilder::forRequest(
            HttpRequest::post('https://api.example/uploads', ['Content-Type' => $body->contentType()], $body),
        );

        $fields = $options[CURLOPT_POSTFIELDS];
        $this->assertIsArray($fields);
        $this->assertSame('photo', $fields['caption']);

        $file = $fields['upload'];
        $this->assertInstanceOf(\CURLStringFile::class, $file);
        $this->assertSame('photo.png', $file->postname);
        $this->assertSame('image/png', $file->mime);

        foreach ($options[CURLOPT_HTTPHEADER] as $line) {
            $this->assertStringStartsNotWith('content-type:', strtolower((string) $line));
        }
    }

    public function test_for_request_multipart_file_from_path_uses_curl_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atp-multipart-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'file-from-disk');

        try {
            $body = new MultipartBody([MultipartFile::fromPath('disk', $path, 'disk.txt', 'text/plain')]);
            $options = CurlOptionsBuilder::forRequest(HttpRequest::post('https://api.example/uploads', body: $body));

            $file = $options[CURLOPT_POSTFIELDS]['disk'];
            $this->assertInstanceOf(\CURLFile::class, $file);
            $this->assertNotInstanceOf(\CURLStringFile::class, $file);
            $this->assertSame($path, $file->name);
            $this->assertSame('disk.txt', $file->postname);
            $this->assertSame('text/plain', $file->mime);
        } finally {
            @unlink($path);
        }
    }

    public function test_for_stream_omits_total_timeout_when_no_deadline(): void
    {
        $options = CurlOptionsBuilder::forStream(
            new StreamRequest('https://api.example/events', ['Accept' => 'text/event-stream']),
        );

        $this->assertSame('GET', $options[CURLOPT_CUSTOMREQUEST]);
        $this->assertArrayNotHasKey(CURLOPT_TIMEOUT_MS, $options);
        $this->assertFalse($options[CURLOPT_NOPROGRESS]);
        $this->assertContains('accept: text/event-stream', $options[CURLOPT_HTTPHEADER]);
    }

    public function test_for_stream_sets_total_timeout_when_deadline_given(): void
    {
        $options = CurlOptionsBuilder::forStream(new StreamRequest('https://api.example/events', streamTimeoutSeconds: 300));

        $this->assertSame(300000, $options[CURLOPT_TIMEOUT_MS]);
    }

    public function test_sanitize_uri_strips_query_fragment_and_userinfo(): void
    {
        $this->assertSame(
            'https://api.example:8443/v1/chats',
            CurlOptionsBuilder::sanitizeUri('https://user:pass@api.example:8443/v1/chats?token=abc#frag'),
        );
        $this->assertSame('http://api.example/x', CurlOptionsBuilder::sanitizeUri('http://api.example/x?q=1'));
    }
}
