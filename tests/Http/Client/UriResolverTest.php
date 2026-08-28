<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Http\Client\UriResolver;
use PHPUnit\Framework\TestCase;

final class UriResolverTest extends TestCase
{
    public function test_appends_canonical_api_prefix_when_base_has_no_prefix(): void
    {
        $resolver = new UriResolver('https://api.envai.dev');

        $this->assertSame('https://api.envai.dev/api/v1/up', $resolver->api('/up'));
    }

    public function test_base_trailing_slash_is_normalized(): void
    {
        $resolver = new UriResolver('https://api.envai.dev/');

        $this->assertSame('https://api.envai.dev/api/v1/up', $resolver->api('/up'));
    }

    public function test_does_not_duplicate_existing_api_prefix(): void
    {
        $resolver = new UriResolver('https://api.envai.dev/api/v1');

        $this->assertSame('https://api.envai.dev/api/v1/up', $resolver->api('/up'));
    }

    public function test_existing_api_prefix_is_detected_case_insensitively_and_kept(): void
    {
        $resolver = new UriResolver('https://api.envai.dev/API/V1/');

        $this->assertSame('https://api.envai.dev/API/V1/up', $resolver->api('/up'));
    }

    public function test_nested_path_is_appended_unchanged(): void
    {
        $resolver = new UriResolver('https://api.envai.dev');

        $this->assertSame(
            'https://api.envai.dev/api/v1/chats/cht_01j5x/messages',
            $resolver->api('/chats/cht_01j5x/messages'),
        );
    }

    public function test_path_without_leading_slash_is_accepted(): void
    {
        $resolver = new UriResolver('https://api.envai.dev');

        $this->assertSame('https://api.envai.dev/api/v1/up', $resolver->api('up'));
    }

    public function test_path_already_carrying_the_prefix_is_not_duplicated(): void
    {
        $resolver = new UriResolver('https://api.envai.dev');

        $this->assertSame('https://api.envai.dev/api/v1/chats', $resolver->api('/api/v1/chats'));
    }

    public function test_path_prefix_is_stripped_even_when_base_has_the_prefix(): void
    {
        $resolver = new UriResolver('https://api.envai.dev/api/v1');

        $this->assertSame('https://api.envai.dev/api/v1/chats', $resolver->api('/api/v1/chats'));
    }

    public function test_query_is_percent_encoded_with_rfc3986(): void
    {
        $resolver = new UriResolver('https://api.envai.dev');

        $this->assertSame(
            'https://api.envai.dev/api/v1/tools?q=a%20b%26c%3Dd&x=caf%C3%A9',
            $resolver->api('/tools', ['q' => 'a b&c=d', 'x' => 'café']),
        );
    }

    public function test_empty_query_does_not_append_question_mark(): void
    {
        $resolver = new UriResolver('https://api.envai.dev');

        $this->assertSame('https://api.envai.dev/api/v1/up', $resolver->api('/up', []));
    }

    public function test_jwks_is_derived_from_origin_without_port(): void
    {
        $resolver = new UriResolver('https://api.envai.dev/api/v1');

        $this->assertSame('https://api.envai.dev/.well-known/jwks.json', $resolver->jwksUrl());
    }

    public function test_jwks_is_derived_from_origin_with_port(): void
    {
        $resolver = new UriResolver('http://localhost:8080');

        $this->assertSame('http://localhost:8080/.well-known/jwks.json', $resolver->jwksUrl());
    }

    public function test_explicit_jwks_url_wins_over_derivation(): void
    {
        $resolver = new UriResolver('https://api.envai.dev', 'https://keys.example.com/envai/jwks.json');

        $this->assertSame('https://keys.example.com/envai/jwks.json', $resolver->jwksUrl());
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('http or https');

        new UriResolver('ftp://api.envai.dev');
    }

    public function test_rejects_empty_or_malformed_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UriResolver('not a url');
    }

    public function test_rejects_non_http_jwks_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('http or https');

        new UriResolver('https://api.envai.dev', 'file:///tmp/jwks.json');
    }

    public function test_rejects_empty_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new UriResolver('https://api.envai.dev'))->api('');
    }
}
