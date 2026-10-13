<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\OperationInventory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Automated inventory verification required by the project quality gate: the
 * operation table in OperationInventory must match the OpenAPI contract
 * exactly, with no missing and no extra entries.
 */
final class InventoryTest extends TestCase
{
    /**
     * Parses every (method, path, operationId) triple from docs/openapi.yaml.
     *
     * @return array<string, array{method: string, path: string}>
     */
    private static function openApiOperations(): array
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi.yaml');

        self::assertIsArray($spec, 'docs/openapi.yaml must parse as a mapping.');
        self::assertArrayHasKey('paths', $spec, 'docs/openapi.yaml must carry a "paths" section.');
        self::assertIsArray($spec['paths']);

        $operations = [];

        foreach ($spec['paths'] as $path => $methods) {
            self::assertIsArray($methods, sprintf('Path %s must be a mapping of methods.', $path));

            foreach ($methods as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                if (! isset($operation['operationId']) || ! is_string($operation['operationId'])) {
                    continue;
                }

                $operations[$operation['operationId']] = [
                    'method' => strtoupper((string) $method),
                    'path' => $path,
                ];
            }
        }

        return $operations;
    }

    public function test_openapi_defines_exactly_63_operations(): void
    {
        $this->assertCount(63, self::openApiOperations());
    }

    public function test_inventory_matches_openapi_exactly(): void
    {
        $expected = self::openApiOperations();
        $actual = OperationInventory::OPERATIONS;

        $this->assertSame(
            array_keys($expected),
            array_keys($actual),
            'OperationInventory must declare exactly the operationIds present in docs/openapi.yaml (no missing, no extra).',
        );

        foreach ($expected as $operationId => $definition) {
            $this->assertArrayHasKey($operationId, $actual, sprintf('Missing operationId "%s".', $operationId));
            $this->assertSame($definition, $actual[$operationId], sprintf('Method or path mismatch for operationId "%s".', $operationId));
        }
    }
}
