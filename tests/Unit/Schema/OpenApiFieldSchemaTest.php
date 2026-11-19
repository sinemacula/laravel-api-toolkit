<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
            examples   : ['a'],
            description: 'An example field',
            undocumented: false,
        );

        self::assertSame('string', $schema->type);
        self::assertSame('email', $schema->format);
        self::assertTrue($schema->nullable);
        self::assertSame(['a', 'b'], $schema->enum);
        self::assertSame(['a'], $schema->examples);
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
        self::assertArrayNotHasKey('examples', $fragment);
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
     * Test that the type keyword is omitted when the type is null even when the
     * field is declared nullable.
     *
     * @return void
     */
    public function testTypeIsOmittedWhenNullEvenWhenNullable(): void
    {
        $schema = new OpenApiFieldSchema(type: null, nullable: true);

        self::assertSame([], $schema->toArray());
    }

    /**
     * Test that declared examples are exported under the examples keyword.
     *
     * @return void
     */
    public function testExamplesAreExportedUnderTheExamplesKeyword(): void
    {
        $schema = new OpenApiFieldSchema(type: 'integer', examples: [42]);

        self::assertSame([
            'type'     => 'integer',
            'examples' => [42],
        ], $schema->toArray());
    }

    /**
     * Test that a declared null example is exported rather than dropped.
     *
     * @return void
     */
    public function testNullExampleIsExported(): void
    {
        $schema = new OpenApiFieldSchema(type: 'string', nullable: true, examples: [null]);

        self::assertSame(['type' => ['string', 'null'], 'examples' => [null]], $schema->toArray());
    }

    /**
     * Test that a reference carries its examples and description beside it.
     *
     * @return void
     */
    public function testReferenceCarriesItsAnnotations(): void
    {
        $schema = new OpenApiFieldSchema(nullable: true, examples: ['active'], description: 'The status.', ref: '#/components/schemas/UserStatus');

        self::assertSame([
            'anyOf'       => [['$ref' => '#/components/schemas/UserStatus'], ['type' => 'null']],
            'examples'    => ['active'],
            'description' => 'The status.',
        ], $schema->toArray());
    }

    /**
     * Test that an undocumented field carries its examples.
     *
     * @return void
     */
    public function testUndocumentedFieldCarriesItsExamples(): void
    {
        $schema = new OpenApiFieldSchema(examples: [['a' => 1]], undocumented: true);

        self::assertSame(['x-undocumented' => true, 'examples' => [['a' => 1]]], $schema->toArray());
    }

    /**
     * Provide schemas with and without a declared shape.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Schema\OpenApiFieldSchema, bool}>
     */
    public static function annotationProvider(): iterable
    {
        yield 'examples and description' => [new OpenApiFieldSchema(examples: [1], description: 'A count.'), true];
        yield 'type' => [new OpenApiFieldSchema(type: 'integer'), false];
        yield 'format' => [new OpenApiFieldSchema(format: 'uuid'), false];
        yield 'enum' => [new OpenApiFieldSchema(enum: ['a']), false];
        yield 'nullable' => [new OpenApiFieldSchema(nullable: true), false];
        yield 'reference' => [OpenApiFieldSchema::reference('#/components/schemas/UserStatus'), false];
        yield 'undocumented' => [OpenApiFieldSchema::undocumented(), false];
    }

    /**
     * Test that only a schema declaring no type, format, enum, nullability,
     * reference or undocumented flag annotates only.
     *
     * @param  \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema  $schema
     * @param  bool  $annotatesOnly
     * @return void
     */
    #[DataProvider('annotationProvider')]
    public function testAnnotatesOnlyWithoutAShape(OpenApiFieldSchema $schema, bool $annotatesOnly): void
    {
        self::assertSame($annotatesOnly, $schema->annotatesOnly());
    }

    /**
     * Test that annotating lays the examples and description over a schema.
     *
     * @return void
     */
    public function testAnnotateLaysExamplesAndDescriptionOverASchema(): void
    {
        $schema = new OpenApiFieldSchema(examples: ['gold'], description: 'The tier.');

        self::assertSame(
            ['type' => 'string', 'description' => 'The tier.', 'examples' => ['gold']],
            $schema->annotate(['type' => 'string', 'description' => 'Inferred.']),
        );
    }

    /**
     * Test that annotating without examples or a description leaves a schema as
     * it is.
     *
     * @return void
     */
    public function testAnnotateWithoutAnnotationsLeavesTheSchema(): void
    {
        self::assertSame(['type' => 'string'], (new OpenApiFieldSchema)->annotate(['type' => 'string']));
    }

    /**
     * Test that the reference factory emits a bare $ref to the named component
     * when the field is not nullable.
     *
     * @return void
     */
    public function testReferenceFactoryEmitsBareRef(): void
    {
        $schema = OpenApiFieldSchema::reference('#/components/schemas/UserStatus');

        self::assertSame('#/components/schemas/UserStatus', $schema->ref);
        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $schema->toArray());
    }

    /**
     * Test that a nullable reference widens to an anyOf with a null member,
     * since a bare $ref cannot itself carry the null type.
     *
     * @return void
     */
    public function testNullableReferenceEmitsAnyOfWithNull(): void
    {
        $schema = OpenApiFieldSchema::reference('#/components/schemas/UserStatus', true);

        self::assertSame([
            'anyOf' => [
                ['$ref' => '#/components/schemas/UserStatus'],
                ['type' => 'null'],
            ],
        ], $schema->toArray());
    }

    /**
     * Test that a reference takes precedence over a declared type, so an enum
     * field never emits both a $ref and an inline type.
     *
     * @return void
     */
    public function testReferenceTakesPrecedenceOverType(): void
    {
        $schema = new OpenApiFieldSchema(type: 'string', ref: '#/components/schemas/UserStatus');

        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $schema->toArray());
    }

    /**
     * Test that a nullable scalar loses only its null member.
     *
     * @return void
     */
    public function testWithoutNullDropsNullFromAScalar(): void
    {
        $schema = new OpenApiFieldSchema(
            type        : 'string',
            format      : 'email',
            nullable    : true,
            enum        : ['a@example.com'],
            examples    : ['a@example.com'],
            description : 'The address.',
            undocumented: true,
            ref         : '#/components/schemas/Address',
        );

        $copy = $schema->withoutNull();

        self::assertNotSame($schema, $copy);
        self::assertTrue($schema->nullable);
        self::assertFalse($copy->nullable);
        self::assertSame('string', $copy->type);
        self::assertSame('email', $copy->format);
        self::assertSame(['a@example.com'], $copy->enum);
        self::assertSame(['a@example.com'], $copy->examples);
        self::assertSame('The address.', $copy->description);
        self::assertTrue($copy->undocumented);
        self::assertSame('#/components/schemas/Address', $copy->ref);
    }

    /**
     * Test that a nullable reference becomes a bare reference.
     *
     * @return void
     */
    public function testWithoutNullUnwrapsANullableReference(): void
    {
        $schema = OpenApiFieldSchema::reference('#/components/schemas/UserStatus', true);

        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $schema->withoutNull()->toArray());
    }

    /**
     * Test that a nullable scalar emits a single type once null is dropped.
     *
     * @return void
     */
    public function testWithoutNullEmitsASingleType(): void
    {
        $schema = new OpenApiFieldSchema(type: 'integer', nullable: true);

        self::assertSame(['type' => 'integer'], $schema->withoutNull()->toArray());
    }
}
