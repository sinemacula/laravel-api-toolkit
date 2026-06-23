<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\OpenApiFieldDeclaration;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Tests for the OpenApiFieldDeclaration fluent carrier.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiFieldDeclaration::class)]
final class OpenApiFieldDeclarationTest extends TestCase
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

        self::assertInstanceOf(OpenApiFieldSchema::class, $schema);
        self::assertSame('string', $schema->type);
        self::assertSame('email', $schema->format);
        self::assertTrue($schema->nullable);
        self::assertSame(['a', 'b'], $schema->enum);
        self::assertSame('a', $schema->example);
        self::assertSame('A status field', $schema->description);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that each setter returns the same carrier for chaining.
     *
     * @return void
     */
    public function testSettersReturnSelf(): void
    {
        $declaration = new OpenApiFieldDeclaration(Field::scalar('name'));

        self::assertSame($declaration, $declaration->type('string'));
        self::assertSame($declaration, $declaration->format('uuid'));
        self::assertSame($declaration, $declaration->nullable());
        self::assertSame($declaration, $declaration->enum(['x']));
        self::assertSame($declaration, $declaration->example('x'));
        self::assertSame($declaration, $declaration->description('desc'));
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

        self::assertFalse($schema->nullable);
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

        self::assertSame($field, $declaration->end());
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

        self::assertNull($schema->type);
        self::assertNull($schema->format);
        self::assertFalse($schema->nullable);
        self::assertNull($schema->enum);
        self::assertNull($schema->description);
        self::assertFalse($schema->undocumented);
    }
}
