<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\OperationInventory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Automated inventory verification required by the project quality gate: the
 * SDK subset must match the corresponding full OpenAPI definitions;
 * unsupported human/internal operations do not become SDK capabilities.
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

    public function test_full_server_contract_and_sdk_subset_sizes_are_explicit(): void
    {
        $this->assertCount(146, self::openApiOperations());
        $this->assertCount(78, OperationInventory::OPERATIONS);
    }

    public function test_every_sdk_operation_matches_the_full_server_contract(): void
    {
        $expected = self::openApiOperations();
        $actual = OperationInventory::OPERATIONS;

        foreach ($actual as $operationId => $definition) {
            $this->assertArrayHasKey($operationId, $expected, sprintf('Missing operationId "%s".', $operationId));
            $this->assertSame($expected[$operationId], $definition, sprintf('Method or path mismatch for operationId "%s".', $operationId));
        }
    }
}
