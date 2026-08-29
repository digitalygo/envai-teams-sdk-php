<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Webhooks;

use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookValidationException;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use PHPUnit\Framework\TestCase;

final class WebhookValidatorTest extends TestCase
{
    public function test_body_limit_is_checked_before_json_decode(): void
    {
        $request = $this->request('{"broken"');

        try {
            (new WebhookValidator(4))->validate($request);
            $this->fail('Expected the body limit to reject the request.');
        } catch (WebhookValidationException $exception) {
            $this->assertStringContainsString('size limit', $exception->getMessage());
            $this->assertSame(9, $exception->context()['body_bytes']);
        }
    }

    public function test_json_media_type_with_charset_is_accepted(): void
    {
        $payload = (new WebhookValidator)->validate($this->request('{"event":"created"}', 'Application/JSON; charset=UTF-8'));

        $this->assertSame(['event' => 'created'], $payload->toArray());
    }

    public function test_json_media_type_suffix_is_accepted(): void
    {
        $payload = (new WebhookValidator)->validate($this->request('{"event":"created"}', 'application/vnd.example+json'));

        $this->assertSame('created', $payload->get('event'));
    }

    public function test_non_json_or_missing_content_type_is_rejected(): void
    {
        foreach ([null, 'text/plain', 'application/xml', 'application/json, application/xml'] as $contentType) {
            try {
                (new WebhookValidator)->validate($this->request('{}', $contentType));
                $this->fail('Expected a non-JSON content type to be rejected.');
            } catch (WebhookValidationException $exception) {
                $this->assertStringContainsString('content type', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->expectException(WebhookValidationException::class);
        $this->expectExceptionMessage('not valid JSON');

        (new WebhookValidator)->validate($this->request('{"event":'));
    }

    public function test_root_list_is_rejected_after_decoding(): void
    {
        try {
            (new WebhookValidator)->validate($this->request('[{"event":"created"}]'));
            $this->fail('Expected a list root to be rejected.');
        } catch (WebhookValidationException $exception) {
            $this->assertStringContainsString('root must be an object', $exception->getMessage());
            $this->assertSame('list', $exception->context()['root_type']);
        }
    }

    public function test_scalar_and_null_roots_are_rejected(): void
    {
        foreach (['null', '"event"', 'true', '42'] as $body) {
            $this->expectException(WebhookValidationException::class);
            (new WebhookValidator)->validate($this->request($body));
        }
    }

    private function request(string $body, ?string $contentType = 'application/json'): WebhookRequest
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];

        return new WebhookRequest('POST', '/webhooks', $headers, $body);
    }
}
