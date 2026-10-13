<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Schema;

use Digitalygo\Envai\Tools\Schema\ToolSchemaDocument;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class SchemaObjectIdentityTest extends TestCase
{
    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(function (int $severity, string $message): bool {
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                $this->deprecations[] = $message;

                return true;
            }

            return false;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        self::assertSame([], $this->deprecations, 'Schema validation must remain deprecation-free on PHP 8.5.');
        parent::tearDown();
    }

    public function test_cache_reuses_object_identity_without_collapsing_equal_schema_objects(): void
    {
        $document = ToolSchemaDocument::prepare([
            'type' => 'object',
            'properties' => ['left' => ['type' => 'integer'], 'right' => ['type' => 'integer']],
        ]);
        $loader = $document->loader;
        $left = $document->schema->properties->left;
        $right = $document->schema->properties->right;
        self::assertEquals($left, $right);
        self::assertNotSame($left, $right);
        $root = $loader->loadObjectSchema($document->schema);
        $leftSchema = $loader->loadObjectSchema($left);
        $rightSchema = $loader->loadObjectSchema($right);
        self::assertSame($root, $loader->loadObjectSchema($document->schema));
        self::assertSame($leftSchema, $loader->loadObjectSchema($left));
        self::assertSame($rightSchema, $loader->loadObjectSchema($right));
        self::assertNotSame($leftSchema, $rightSchema);
        $validator = new Validator($loader);
        self::assertTrue($validator->validate((object) ['left' => 1, 'right' => 2], $root)->isValid());
        self::assertFalse($validator->validate((object) ['left' => 'invalid', 'right' => 2], $root)->isValid());
    }

    public function test_productive_recursive_references_validate_and_clear_active_state_after_failure(): void
    {
        $document = ToolSchemaDocument::prepare([
            'type' => 'object',
            'properties' => ['value' => ['type' => 'integer'], 'next' => ['$ref' => '#']],
            'required' => ['value'],
            'additionalProperties' => false,
        ]);
        $validator = new Validator($document->loader);
        $valid = (object) ['value' => 1, 'next' => (object) ['value' => 2]];
        $invalid = (object) ['value' => 1, 'next' => (object) ['value' => 'invalid']];
        self::assertTrue($validator->validate($valid, $document->schema)->isValid());
        self::assertFalse($validator->validate($invalid, $document->schema)->isValid());
        self::assertTrue($validator->validate($valid, $document->schema)->isValid());
    }

    public function test_nonproductive_reference_cycles_fail_closed_consistently(): void
    {
        $document = ToolSchemaDocument::prepare(['$ref' => '#']);
        $validator = new Validator($document->loader);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $validator->validate(new \stdClass, $document->schema);
                self::fail('Nonproductive reference cycle accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Nonproductive schema reference cycle.', $exception->getMessage());
            }
        }
    }

    public function test_literal_pointer_promotion_preserves_validation_and_cached_guard_identity(): void
    {
        $document = ToolSchemaDocument::prepare([
            'type' => 'object',
            'x-literal' => ['type' => 'integer'],
            'properties' => ['value' => ['$ref' => '#/x-literal']],
        ]);
        $literal = $document->schema->{'x-literal'};
        $first = $document->loader->loadObjectSchema($literal);
        self::assertSame($first, $document->loader->loadObjectSchema($literal));
        $validator = new Validator($document->loader);
        self::assertTrue($validator->validate((object) ['value' => 3], $document->schema)->isValid());
        self::assertFalse($validator->validate((object) ['value' => 'invalid'], $document->schema)->isValid());
        self::assertTrue($validator->validate((object) ['value' => 4], $document->schema)->isValid());
    }
}
