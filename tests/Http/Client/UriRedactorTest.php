<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Http\Client\UriRedactor;
use PHPUnit\Framework\TestCase;

final class UriRedactorTest extends TestCase
{
    public function test_strips_userinfo_query_and_fragment_keeping_scheme_host_port_path(): void
    {
        $this->assertSame(
            'https://api.envai.example:8443/v1/chats',
            UriRedactor::redact('https://user:pass@api.envai.example:8443/v1/chats?token=abc#frag'),
        );
    }

    public function test_strips_query_from_signed_artifact_and_webhook_uris(): void
    {
        $this->assertSame(
            'https://api.envai.dev/artifacts/download',
            UriRedactor::redact('https://api.envai.dev/artifacts/download?path=a%2Fb&signature=6f8d2c4a9b1e5f7a&expires=1786320000'),
        );

        $this->assertSame(
            'https://api.envai.dev/webhooks/providers/replicate',
            UriRedactor::redact('https://api.envai.dev/webhooks/providers/replicate?callback_token=0192f3a4-6b1c'),
        );
    }

    public function test_keeps_ipv6_host_with_brackets(): void
    {
        $this->assertSame(
            'https://[2001:db8::1]:8443/x',
            UriRedactor::redact('https://[2001:db8::1]:8443/x?q=1#f'),
        );
    }

    public function test_keeps_plain_uris_unchanged(): void
    {
        $this->assertSame('https://api.envai.dev/up', UriRedactor::redact('https://api.envai.dev/up'));
        $this->assertSame('http://api.envai.dev/up', UriRedactor::redact('http://api.envai.dev/up'));
    }

    public function test_relative_references_keep_the_path_but_strip_the_query(): void
    {
        $this->assertSame('/up', UriRedactor::redact('/up?token=abc'));
    }

    public function test_unparseable_uris_are_returned_as_is(): void
    {
        $this->assertSame('http://user@:80', UriRedactor::redact('http://user@:80'));
    }
}
