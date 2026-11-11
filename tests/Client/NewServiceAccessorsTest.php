<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\ChatCronsService;
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\ProjectReportsService;
use Digitalygo\Envai\Client\ProjectSkillsService;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use PHPUnit\Framework\TestCase;

/** Lazy, cached wiring of the three new management service accessors. */
final class NewServiceAccessorsTest extends TestCase
{
    public function test_new_management_accessors_are_cached_per_client(): void
    {
        $client = new EnvaiClient(
            new EnvaiConfig('https://envai.example.com', new SensitiveString('sk-test-secret')),
            new FakeTransport,
        );

        $cases = [
            'chatCrons' => ChatCronsService::class,
            'projectSkills' => ProjectSkillsService::class,
            'projectReports' => ProjectReportsService::class,
        ];

        foreach ($cases as $getter => $class) {
            $first = $client->{$getter}();
            self::assertInstanceOf($class, $first);
            self::assertSame($first, $client->{$getter}(), sprintf('Accessor %s must be lazily cached.', $getter));
        }
    }

    public function test_accessors_are_independent_across_clients(): void
    {
        $config = static fn (): EnvaiConfig => new EnvaiConfig('https://envai.example.com', new SensitiveString('sk-test-secret'));
        $first = new EnvaiClient($config(), new FakeTransport);
        $second = new EnvaiClient($config(), new FakeTransport);

        foreach (['chatCrons', 'projectSkills', 'projectReports'] as $getter) {
            self::assertNotSame($first->{$getter}(), $second->{$getter}());
        }
    }

    public function test_accessor_return_types_are_the_concrete_services(): void
    {
        $expected = [
            'chatCrons' => ChatCronsService::class,
            'projectSkills' => ProjectSkillsService::class,
            'projectReports' => ProjectReportsService::class,
        ];

        foreach ($expected as $getter => $class) {
            $type = (new \ReflectionMethod(EnvaiClient::class, $getter))->getReturnType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertSame($class, $type->getName());
        }
    }
}
