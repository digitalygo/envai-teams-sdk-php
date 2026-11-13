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
        $this->assertCount(92, OperationInventory::OPERATIONS);
    }

    /** @return array<string, array{method: string, path: string}> */
    private static function managementOperations(): array
    {
        return [
            'deleteWorkspace' => ['method' => 'DELETE', 'path' => '/workspaces/{workspace_id}'],
            'updateChat' => ['method' => 'PUT', 'path' => '/workspaces/{workspace_id}/chats/{chat_id}'],
            'deleteChat' => ['method' => 'DELETE', 'path' => '/workspaces/{workspace_id}/chats/{chat_id}'],
            'listChatCrons' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/crons'],
            'getChatCron' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/crons/{cron_id}'],
            'updateChatCron' => ['method' => 'PATCH', 'path' => '/workspaces/{workspace_id}/crons/{cron_id}'],
            'deleteChatCron' => ['method' => 'DELETE', 'path' => '/workspaces/{workspace_id}/crons/{cron_id}'],
            'listProjectSkills' => ['method' => 'GET', 'path' => '/projects/{project_id}/skills'],
            'createProjectSkill' => ['method' => 'POST', 'path' => '/projects/{project_id}/skills'],
            'getProjectSkill' => ['method' => 'GET', 'path' => '/projects/{project_id}/skills/{skill_id}'],
            'updateProjectSkill' => ['method' => 'PUT', 'path' => '/projects/{project_id}/skills/{skill_id}'],
            'deleteProjectSkill' => ['method' => 'DELETE', 'path' => '/projects/{project_id}/skills/{skill_id}'],
            'getProjectUsage' => ['method' => 'GET', 'path' => '/projects/{project_id}/usage'],
            'getProjectLogs' => ['method' => 'GET', 'path' => '/projects/{project_id}/logs'],
        ];
    }

    public function test_management_operations_are_all_declared(): void
    {
        $expected = self::openApiOperations();

        foreach (self::managementOperations() as $operationId => $definition) {
            $this->assertArrayHasKey($operationId, OperationInventory::OPERATIONS, sprintf('Missing management operation "%s".', $operationId));
            $this->assertSame($definition, OperationInventory::OPERATIONS[$operationId]);
            $this->assertSame($definition, $expected[$operationId] ?? null);
        }

        $this->assertCount(14, self::managementOperations());
    }

    public function test_human_admin_and_financial_operations_are_not_sdk_capabilities(): void
    {
        foreach (['createCompany', 'getCompanyUsage', 'getProjectSpendLimit', 'updateProjectSpendLimit', 'deleteProject', 'deleteCompany', 'login', 'logout', 'inviteCompanyMember'] as $operationId) {
            $this->assertArrayHasKey($operationId, self::openApiOperations());
            $this->assertArrayNotHasKey($operationId, OperationInventory::OPERATIONS, sprintf('Human/financial operation "%s" must stay out of the SDK subset.', $operationId));
        }
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
