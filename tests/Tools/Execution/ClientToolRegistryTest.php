<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use PHPUnit\Framework\TestCase;

final class ClientToolRegistryTest extends TestCase
{
    public function test_class_string_entries_default_to_120_seconds(): void
    {
        $registry = ClientToolRegistry::fromArray(['echo' => FlexibleTool::class]);

        $tool = $registry->lookup('echo');

        $this->assertNotNull($tool);
        $this->assertSame('echo', $tool->name);
        $this->assertSame(FlexibleTool::class, $tool->class);
        $this->assertSame(120, $tool->timeoutSeconds);
    }

    public function test_array_entries_carry_an_explicit_timeout(): void
    {
        $registry = ClientToolRegistry::fromArray([
            'forecast' => ['class' => FlexibleTool::class, 'timeout_seconds' => 60],
        ]);

        $tool = $registry->lookup('forecast');

        $this->assertNotNull($tool);
        $this->assertSame(60, $tool->timeoutSeconds);
    }

    public function test_timeout_is_clamped_to_the_platform_range(): void
    {
        $registry = ClientToolRegistry::fromArray([
            'tiny' => ['class' => FlexibleTool::class, 'timeout_seconds' => 0],
            'huge' => ['class' => FlexibleTool::class, 'timeout_seconds' => 900],
        ]);

        $this->assertSame(1, $registry->lookup('tiny')?->timeoutSeconds);
        $this->assertSame(600, $registry->lookup('huge')?->timeoutSeconds);
    }

    public function test_lookup_returns_null_for_unknown_names(): void
    {
        $registry = ClientToolRegistry::fromArray(['echo' => FlexibleTool::class]);

        $this->assertNull($registry->lookup('ghost'));
    }

    public function test_names_lists_registered_tools(): void
    {
        $registry = ClientToolRegistry::fromArray([
            'beta' => FlexibleTool::class,
            'alpha' => FlexibleTool::class,
        ]);

        $this->assertSame(['beta', 'alpha'], $registry->names());
    }

    public function test_missing_class_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('class');

        ClientToolRegistry::fromArray(['echo' => ['timeout_seconds' => 30]]);
    }

    public function test_non_subclass_class_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must exist and implement');

        ClientToolRegistry::fromArray(['echo' => \stdClass::class]);
    }

    public function test_unknown_class_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClientToolRegistry::fromArray(['echo' => 'App\\Ai\\Tools\\GhostTool']);
    }

    public function test_invalid_timeout_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout_seconds');

        ClientToolRegistry::fromArray(['echo' => ['class' => FlexibleTool::class, 'timeout_seconds' => 'fast']]);
    }

    public function test_invalid_entry_shape_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClientToolRegistry::fromArray(['echo' => 42]);
    }
}
