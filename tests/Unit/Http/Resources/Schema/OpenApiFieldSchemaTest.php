<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Tests for the OpenApiFieldSchema value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiFieldSchema::class)]
final class OpenApiFieldSchemaTest extends TestCase
{
    /**
     * Test that constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $schema = new OpenApiFieldSchema(
            type       : 'string',
            format     : 'email',
            nullable   : true,
            enum       : ['a', 'b'],
            example    : 'a',
            description: 'An example field',
            undocumented: false,
        );

        self::assertSame('string', $schema->type);
        self::assertSame('email', $schema->format);
        self::assertTrue($schema->nullable);
        self::assertSame(['a', 'b'], $schema->enum);
        self::assertSame('a', $schema->example);
        self::assertSame('An example field', $schema->description);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that a typed schema emits the expected JSON Schema fragment.
     *
     * @return void
     */
    public function testTypedSchemaEmitsFragment(): void
    {
        $schema = new OpenApiFieldSchema(
            type       : 'string',
            format     : 'uuid',
            description: 'The identifier',
        );

        self::assertSame([
            'type'        => 'string',
            'format'      => 'uuid',
            'description' => 'The identifier',
        ], $schema->toArray());
    }

    /**
     * Test that a nullable schema emits a 2020-12 type-array.
     *
     * @return void
     */
    public function testNullableSchemaEmitsTypeArray(): void
    {
        $schema = new OpenApiFieldSchema(type: 'integer', nullable: true);

        self::assertSame(['type' => ['integer', 'null']], $schema->toArray());
    }

    /**
     * Test that a non-nullable schema emits a scalar type.
     *
     * @return void
     */
    public function testNonNullableSchemaEmitsScalarType(): void
    {
        $schema = new OpenApiFieldSchema(type: 'integer');

        self::assertSame(['type' => 'integer'], $schema->toArray());
    }

    /**
     * Test that an enum schema includes the enumerated values.
     *
     * @return void
     */
    public function testEnumSchemaIncludesValues(): void
    {
        $schema = new OpenApiFieldSchema(type: 'string', enum: ['draft', 'published']);

        self::assertSame([
            'type' => 'string',
            'enum' => ['draft', 'published'],
        ], $schema->toArray());
    }

    /**
     * Test that the undocumented factory produces a flagged schema.
     *
     * @return void
     */
    public function testUndocumentedFactoryFlagsSchema(): void
    {
        $schema = OpenApiFieldSchema::undocumented();

        self::assertTrue($schema->undocumented);
        self::assertNull($schema->type);
    }

    /**
     * Test that an undocumented schema emits the extension marker with no type.
     *
     * @return void
     */
    public function testUndocumentedSchemaEmitsExtensionMarker(): void
    {
        $schema = OpenApiFieldSchema::undocumented();

        self::assertSame(['x-undocumented' => true], $schema->toArray());
    }

    /**
     * Test that an undocumented schema carries an optional description.
     *
     * @return void
     */
    public function testUndocumentedSchemaCarriesDescription(): void
    {
        $schema = OpenApiFieldSchema::undocumented('Computed at runtime');

        self::assertSame([
            'x-undocumented' => true,
            'description'    => 'Computed at runtime',
        ], $schema->toArray());
    }

    /**
     * Test that null and empty properties are omitted from the fragment.
     *
     * @return void
     */
    public function testNullPropertiesAreOmitted(): void
    {
        $schema = new OpenApiFieldSchema(type: 'boolean');

        $fragment = $schema->toArray();

        self::assertArrayNotHasKey('format', $fragment);
        self::assertArrayNotHasKey('enum', $fragment);
        self::assertArrayNotHasKey('example', $fragment);
        self::assertArrayNotHasKey('description', $fragment);
    }

    /**
     * Test that a schema with no type emits an empty fragment.
     *
     * @return void
     */
    public function testSchemaWithNoTypeEmitsEmptyFragment(): void
    {
        $schema = new OpenApiFieldSchema;

        self::assertSame([], $schema->toArray());
    }

    /**
     * Test that an example value is included in the fragment.
     *
     * @return void
     */
    public function testExampleIsIncluded(): void
    {
        $schema = new OpenApiFieldSchema(type: 'integer', example: 42);

        self::assertSame([
            'type'    => 'integer',
            'example' => 42,
        ], $schema->toArray());
    }
}
