<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\OpenApiFieldDeclaration;
use SineMacula\ApiToolkit\Http\Resources\Schema\OpenApiFieldSchema;

/**
 * Tests for the OpenApiFieldDeclaration fluent carrier.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiFieldDeclaration::class)]
class OpenApiFieldDeclarationTest extends TestCase
{
    /**
     * Test that the fluent setters chain and freeze into a schema.
     *
     * @return void
     */
    public function testFluentSettersFreezeIntoSchema(): void
    {
        $declaration = new OpenApiFieldDeclaration(Field::scalar('status'));

        $schema = $declaration
            ->type('string')
            ->format('email')
            ->nullable()
            ->enum(['a', 'b'])
            ->example('a')
            ->description('A status field')
            ->toSchema();

        static::assertInstanceOf(OpenApiFieldSchema::class, $schema);
        static::assertSame('string', $schema->type);
        static::assertSame('email', $schema->format);
        static::assertTrue($schema->nullable);
        static::assertSame(['a', 'b'], $schema->enum);
        static::assertSame('a', $schema->example);
        static::assertSame('A status field', $schema->description);
        static::assertFalse($schema->undocumented);
    }

    /**
     * Test that each setter returns the same carrier for chaining.
     *
     * @return void
     */
    public function testSettersReturnSelf(): void
    {
        $declaration = new OpenApiFieldDeclaration(Field::scalar('name'));

        static::assertSame($declaration, $declaration->type('string'));
        static::assertSame($declaration, $declaration->format('uuid'));
        static::assertSame($declaration, $declaration->nullable());
        static::assertSame($declaration, $declaration->enum(['x']));
        static::assertSame($declaration, $declaration->example('x'));
        static::assertSame($declaration, $declaration->description('desc'));
    }

    /**
     * Test that nullable defaults to true and accepts an explicit false.
     *
     * @return void
     */
    public function testNullableAcceptsExplicitFalse(): void
    {
        $declaration = new OpenApiFieldDeclaration(Field::scalar('name'));

        $schema = $declaration->type('string')->nullable(false)->toSchema();

        static::assertFalse($schema->nullable);
    }

    /**
     * Test that end() returns the owning definition.
     *
     * @return void
     */
    public function testEndReturnsParentDefinition(): void
    {
        $field       = Field::scalar('email');
        $declaration = new OpenApiFieldDeclaration($field);

        static::assertSame($field, $declaration->end());
    }

    /**
     * Test that a freshly constructed declaration freezes to an empty schema.
     *
     * @return void
     */
    public function testEmptyDeclarationFreezesToEmptySchema(): void
    {
        $declaration = new OpenApiFieldDeclaration(Field::scalar('name'));

        $schema = $declaration->toSchema();

        static::assertNull($schema->type);
        static::assertNull($schema->format);
        static::assertFalse($schema->nullable);
        static::assertNull($schema->enum);
        static::assertNull($schema->description);
        static::assertFalse($schema->undocumented);
    }
}
