<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Models\Wiki\ConversionState;
use Digitalygo\Envai\Models\Wiki\WikiSource;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class ConversionDiagnosticsTest extends TestCase
{
    public function test_legacy_diagnostics_remain_unknown_and_constructor_arguments_are_compatible(): void
    {
        $legacy = ConversionState::fromArray(['status' => 'queued']);
        self::assertNull($legacy->errorCode);
        self::assertNull($legacy->recoverable);
        self::assertNull($legacy->nextRetryAt);
        self::assertSame(['status' => 'queued'], $legacy->toArray());
        self::assertTrue((new ConversionState('running', ['future' => true]))->extra['future']);
        $clear = ['status' => 'completed', 'error_code' => null, 'recoverable' => false, 'next_retry_at' => null];
        self::assertEquals($clear, ConversionState::fromArray($clear)->toArray());
    }

    public function test_scheduled_recovery_and_terminal_failures_have_typed_diagnostics(): void
    {
        foreach (['source-conversion-retry', 'source-conversion-no-audio', 'source-conversion-exhausted'] as $name) {
            $wire = WikiContractTest::fixture($name)['data'];
            $state = WikiSource::fromArray($wire)->conversion;
            self::assertSame($wire['conversion']['error_code'], $state->errorCode);
            self::assertSame($wire['conversion']['recoverable'], $state->recoverable);
            self::assertSame($wire['conversion']['next_retry_at'], $state->nextRetryAt);
            self::assertEquals($wire['conversion'], $state->toArray());
        }
    }

    public function test_diagnostic_types_are_validated_without_coercion(): void
    {
        foreach ([['error_code' => 123], ['recoverable' => null], ['recoverable' => 'false'], ['next_retry_at' => 1]] as $changes) {
            try {
                ConversionState::fromArray(['status' => 'failed', ...$changes]);
                self::fail('Malformed conversion diagnostic accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $partial = ConversionState::fromArray(['status' => 'failed', 'error_code' => 'future_code', 'future_field' => true]);
        self::assertSame('future_code', $partial->toArray()['error_code']);
        self::assertTrue($partial->extra['future_field']);
        self::assertArrayNotHasKey('recoverable', $partial->toArray());
    }

    public function test_shared_diagnostic_schema_rejects_invalid_time_and_boolean_values(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/wiki.json'));
        $schema->{'$ref'} = '#/$defs/ConversionState';
        $data = WikiContractTest::fixture('source-conversion-retry')['data']['conversion'];
        foreach ([['next_retry_at' => 'tomorrow'], ['recoverable' => 'true'], ['error_code' => []], ['error_code' => 'provider failed: raw response body']] as $changes) {
            self::assertFalse((new Validator)->validate(json_decode(json_encode(array_replace($data, $changes))), $schema)->isValid());
        }
    }
}
