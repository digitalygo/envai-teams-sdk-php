<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Models\Common;

use Digitalygo\Envai\Models\Common\OptionalField;
use PHPUnit\Framework\TestCase;

final class OptionalFieldTest extends TestCase
{
    public function test_absent_is_not_present(): void
    {
        $field = OptionalField::absent();

        $this->assertTrue($field->isAbsent());
        $this->assertFalse($field->isPresent());
        $this->assertNull($field->orNull());
    }

    public function test_null_value_is_still_present(): void
    {
        $field = OptionalField::of(null);

        $this->assertTrue($field->isPresent());
        $this->assertFalse($field->isAbsent());
        $this->assertNull($field->value());
    }

    public function test_explicit_value_round_trips(): void
    {
        $field = OptionalField::of('value');

        $this->assertTrue($field->isPresent());
        $this->assertSame('value', $field->value());
        $this->assertSame('value', $field->orNull());
    }

    public function test_value_throws_when_absent(): void
    {
        $this->expectException(\LogicException::class);

        OptionalField::absent()->value();
    }
}
