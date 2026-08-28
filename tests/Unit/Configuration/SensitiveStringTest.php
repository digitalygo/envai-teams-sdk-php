<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Configuration;

use Digitalygo\Envai\Configuration\SensitiveString;
use PHPUnit\Framework\TestCase;

final class SensitiveStringTest extends TestCase
{
    public function test_to_string_redacts(): void
    {
        $secret = new SensitiveString('sk-super-secret');

        $this->assertSame('[redacted]', (string) $secret);
    }

    public function test_var_dump_redacts(): void
    {
        $secret = new SensitiveString('sk-super-secret');

        ob_start();
        var_dump($secret);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringNotContainsString('sk-super-secret', $output);
        $this->assertStringContainsString('[redacted]', $output);
    }

    public function test_json_encode_redacts(): void
    {
        $secret = new SensitiveString('sk-super-secret');

        $json = json_encode($secret);

        $this->assertIsString($json);
        $this->assertStringNotContainsString('sk-super-secret', $json);
        $this->assertStringContainsString('[redacted]', $json);
    }

    public function test_var_export_redacts(): void
    {
        $secret = new SensitiveString('sk-super-secret');

        $export = var_export($secret, true);

        $this->assertStringNotContainsString('sk-super-secret', $export);
        $this->assertStringContainsString('SensitiveString::__set_state', $export);
    }

    public function test_reveal_returns_the_secret(): void
    {
        $secret = new SensitiveString('sk-super-secret');

        $this->assertSame('sk-super-secret', $secret->reveal());
    }

    public function test_serialize_never_contains_the_secret(): void
    {
        $secret = new SensitiveString('sk-super-secret');

        $serialized = serialize($secret);

        $this->assertStringNotContainsString('sk-super-secret', $serialized);

        $restored = unserialize($serialized);

        $this->assertInstanceOf(SensitiveString::class, $restored);
        $this->assertSame('[redacted]', $restored->reveal());
    }
}
