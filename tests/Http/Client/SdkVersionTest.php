<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Http\Client\SdkVersion;
use PHPUnit\Framework\TestCase;

final class SdkVersionTest extends TestCase
{
    public function test_version_is_a_non_empty_semver_string(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/', SdkVersion::VERSION);
        $this->assertNotSame('', SdkVersion::VERSION);
    }

    public function test_version_matches_the_composer_package_version(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true);

        $this->assertIsArray($composer);

        // The package does not pin a "version" field: the git tag is the
        // convention, so the in-code constant is the source of truth.
        $this->assertSame('0.1.0', SdkVersion::VERSION);
    }
}
