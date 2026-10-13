<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Models\Wiki\WikiSource;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ControlPlaneHttpFixtureTest extends TestCase
{
    private function response(string $name, string $schema): array
    {
        $folder = dirname(__DIR__).'/fixtures/control-plane-http/';
        $provenance = json_decode(file_get_contents($folder.'provenance.json'), true, 512, JSON_THROW_ON_ERROR);
        $bytes = file_get_contents($folder.$name.'.json');
        self::assertSame($provenance['files'][$name.'.json']['bytes'], strlen($bytes));
        self::assertSame($provenance['files'][$name.'.json']['sha256'], hash('sha256', $bytes));
        $contract = Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi.yaml');
        $contract['$ref'] = '#/components/schemas/'.$schema;
        self::assertTrue((new Validator)->validate(json_decode($bytes)->data, json_decode(json_encode($contract)))->isValid());

        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    public function test_actual_source_response_preserves_scheduled_conversion_diagnostics(): void
    {
        $wire = $this->response('source-conversion-retry', 'WikiSource');
        $source = WikiSource::fromArray($wire);
        self::assertSame('provider_limit_exceeded', $source->conversion->errorCode);
        self::assertTrue($source->conversion->recoverable);
        self::assertNotNull($source->conversion->nextRetryAt);
        self::assertEquals($wire, $source->toArray());
    }

    public function test_actual_legacy_completion_keeps_numeric_cost_without_fabricating_usage(): void
    {
        $wire = $this->response('completion-legacy', 'Completion');
        $completion = Completion::fromArray($wire);
        self::assertSame(0.25, $completion->costUsd);
        self::assertNull($completion->terminalSnapshot);
        self::assertNull($completion->usageCost);
        self::assertArrayNotHasKey('usage_cost', $completion->toArray());
        unset($wire['terminal_snapshot']);
        self::assertEquals($wire, $completion->toArray());
    }
}
