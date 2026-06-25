<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;

/**
 * Tests for the FieldTypeResolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldTypeResolver::class)]
final class FieldTypeResolverTest extends TestCase
{
    /**
     * Test that a declared schema is returned verbatim and is never overridden
     * by inference, even when the field maps to an inferrable column whose
     * inferred type disagrees with the declaration.
     *
     * @return void
     */
    public function testDeclaredSchemaWinsOverInferrableColumn(): void
    {
        $declared = new OpenApiFieldSchema(type: 'string', format: 'email');
        $resolver = $this->makeResolver([
            'email' => new ColumnDefinition(name: 'email', typeName: 'bigint', nullable: false),
        ]);

        $schema = $resolver->resolve('email', $this->field(openApi: $declared), User::class);

        self::assertSame($declared, $schema);
        self::assertSame('string', $schema->type);
        self::assertSame('email', $schema->format);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that a plain scalar backed by a typed, non-null column is inferred
     * with a concrete type and is not flagged.
     *
     * @return void
     */
    public function testPlainScalarColumnIsInferred(): void
    {
        $resolver = $this->makeResolver([
            'title' => new ColumnDefinition(name: 'title', typeName: 'varchar', nullable: false),
        ]);

        $schema = $resolver->resolve('title', $this->field(), Post::class);

        self::assertSame('string', $schema->type);
        self::assertFalse($schema->nullable);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that a nullable column produces a nullable inferred schema, so
     * nullability is never dropped.
     *
     * @return void
     */
    public function testNullableColumnIsInferredNullable(): void
    {
        $resolver = $this->makeResolver([
            'password' => new ColumnDefinition(name: 'password', typeName: 'varchar', nullable: true),
        ]);

        $schema = $resolver->resolve('password', $this->field(), User::class);

        self::assertSame('string', $schema->type);
        self::assertTrue($schema->nullable);
    }

    /**
     * Test that a model boolean cast takes precedence over the column type,
     * resolving the tinyint ambiguity in favour of boolean.
     *
     * @return void
     */
    public function testBooleanCastOverridesColumnType(): void
    {
        $resolver = $this->makeResolver([
            'published' => new ColumnDefinition(name: 'published', typeName: 'tinyint', nullable: false),
        ]);

        $schema = $resolver->resolve('published', $this->field(), Post::class);

        self::assertSame('boolean', $schema->type);
    }

    /**
     * Test that an enum cast that carries no JSON-Schema-relevant mapping falls
     * back to the column type rather than producing a wrong shape.
     *
     * @return void
     */
    public function testEnumCastFallsBackToColumnType(): void
    {
        $resolver = $this->makeResolver([
            'status' => new ColumnDefinition(name: 'status', typeName: 'varchar', nullable: false),
        ]);

        $schema = $resolver->resolve('status', $this->field(), User::class);

        self::assertSame('string', $schema->type);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that an aliased scalar field is inferred from the column matching
     * its presentation key, since the field key is the attribute the value is
     * read from.
     *
     * @return void
     */
    public function testAliasedScalarInfersFromUnderlyingColumn(): void
    {
        $resolver = $this->makeResolver([
            'name' => new ColumnDefinition(name: 'name', typeName: 'varchar', nullable: false),
        ]);

        $schema = $resolver->resolve('name', $this->field(), User::class);

        self::assertSame('string', $schema->type);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that a timestamp-style column resolves to a date-time format.
     *
     * @return void
     */
    public function testTimestampColumnResolvesToDateTime(): void
    {
        $resolver = $this->makeResolver([
            'created_at' => new ColumnDefinition(name: 'created_at', typeName: 'timestamp', nullable: true),
        ]);

        $schema = $resolver->resolve('created_at', $this->field(), Post::class);

        self::assertSame('string', $schema->type);
        self::assertSame('date-time', $schema->format);
        self::assertTrue($schema->nullable);
    }

    /**
     * Test that a date column resolves to a date format.
     *
     * @return void
     */
    public function testDateColumnResolvesToDate(): void
    {
        $resolver = $this->makeResolver([
            'birth_date' => new ColumnDefinition(name: 'birth_date', typeName: 'date', nullable: false),
        ]);

        $schema = $resolver->resolve('birth_date', $this->field(), User::class);

        self::assertSame('string', $schema->type);
        self::assertSame('date', $schema->format);
    }

    /**
     * Test that an accessor field with no declaration is flagged undocumented
     * rather than inferred or guessed.
     *
     * @return void
     */
    public function testAccessorFieldIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([
            'full_label' => new ColumnDefinition(name: 'full_label', typeName: 'varchar', nullable: false),
        ]);

        $schema = $resolver->resolve('full_label', $this->field(accessor: 'name'), User::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that a computed field with no declaration is flagged undocumented.
     *
     * @return void
     */
    public function testComputedFieldIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([
            'score' => new ColumnDefinition(name: 'score', typeName: 'integer', nullable: false),
        ]);

        $schema = $resolver->resolve('score', $this->field(compute: static fn () => 1), User::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that a relation field with a child resource is flagged undocumented,
     * because its cardinality is unknowable from the compiled definition alone.
     *
     * @return void
     */
    public function testRelationFieldIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([]);

        $field = $this->field(relation: 'organization', resource: 'OrganizationResource');

        $schema = $resolver->resolve('organization', $field, User::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that a guarded field is flagged undocumented when it carries no
     * declaration, so a conditionally present value is never confidently typed.
     *
     * @return void
     */
    public function testGuardedFieldIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([
            'email' => new ColumnDefinition(name: 'email', typeName: 'varchar', nullable: false),
        ]);

        $field = $this->field(guards: [static fn () => true]);

        $schema = $resolver->resolve('email', $field, User::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that a field carrying a transformer is flagged undocumented, since
     * the transformer changes the emitted value away from the column type.
     *
     * @return void
     */
    public function testTransformedFieldIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([
            'name' => new ColumnDefinition(name: 'name', typeName: 'varchar', nullable: false),
        ]);

        $field = $this->field(transformers: [static fn ($resource, $value) => $value]);

        $schema = $resolver->resolve('name', $field, User::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that a plain scalar whose key matches no column is flagged
     * undocumented rather than guessed.
     *
     * @return void
     */
    public function testScalarWithNoMatchingColumnIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([
            'title' => new ColumnDefinition(name: 'title', typeName: 'varchar', nullable: false),
        ]);

        $schema = $resolver->resolve('virtual_attribute', $this->field(), Post::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that a plain scalar backed by a column of unknown driver type is
     * flagged undocumented rather than guessed.
     *
     * @return void
     */
    public function testScalarWithUnknownColumnTypeIsFlaggedUndocumented(): void
    {
        $resolver = $this->makeResolver([
            'shape' => new ColumnDefinition(name: 'shape', typeName: 'geometry', nullable: false),
        ]);

        $schema = $resolver->resolve('shape', $this->field(), Post::class);

        $this->assertFlagged($schema);
    }

    /**
     * Test that no flagged field ever carries a concrete narrow type -- the
     * flag-never-guess invariant -- across every opaque and unresolvable case.
     *
     * @return void
     */
    public function testFlaggedFieldsNeverCarryAConcreteType(): void
    {
        $resolver = $this->makeResolver([
            'name' => new ColumnDefinition(name: 'name', typeName: 'varchar', nullable: false),
        ]);

        $opaqueFields = [
            'accessor'    => $this->field(accessor: 'name'),
            'compute'     => $this->field(compute: static fn () => 1),
            'relation'    => $this->field(relation: 'posts', resource: 'PostResource'),
            'guard'       => $this->field(guards: [static fn () => true]),
            'transformer' => $this->field(transformers: [static fn ($resource, $value) => $value]),
        ];

        foreach ($opaqueFields as $label => $field) {
            $schema = $resolver->resolve('name', $field, User::class);

            self::assertTrue($schema->undocumented, "Field '{$label}' should be flagged undocumented");
            self::assertNull($schema->type, "Flagged field '{$label}' must carry no concrete type");
            self::assertNull($schema->format, "Flagged field '{$label}' must carry no format");
        }
    }

    /**
     * Test that the resolver returns an OpenApiFieldSchema instance.
     *
     * @return void
     */
    public function testReturnsOpenApiFieldSchemaInstance(): void
    {
        $resolver = $this->makeResolver([
            'name' => new ColumnDefinition(name: 'name', typeName: 'varchar', nullable: false),
        ]);

        self::assertInstanceOf(OpenApiFieldSchema::class, $resolver->resolve('name', $this->field(), User::class));
    }

    /**
     * Test that the resolver works against a model without a configured cast
     * map, inferring purely from the column type.
     *
     * @return void
     */
    public function testInfersFromColumnWhenModelDeclaresNoRelevantCast(): void
    {
        $resolver = $this->makeResolver([
            'name' => new ColumnDefinition(name: 'name', typeName: 'varchar', nullable: false),
        ]);

        $schema = $resolver->resolve('name', $this->field(), Tag::class);

        self::assertSame('string', $schema->type);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Build a resolver whose introspector returns the given column definitions
     * for any model.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition>  $columns
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function makeResolver(array $columns): FieldTypeResolver
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn($columns);

        return new FieldTypeResolver($introspector, new ColumnTypeMapper);
    }

    /**
     * Build a compiled field definition with the given attributes, defaulting
     * to a plain scalar.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $relation
     * @param  string|null  $resource
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema|null  $openApi
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function field(
        mixed $accessor = null,
        mixed $compute = null,
        ?string $relation = null,
        ?string $resource = null,
        array $guards = [],
        array $transformers = [],
        ?OpenApiFieldSchema $openApi = null,
    ): CompiledFieldDefinition {
        return new CompiledFieldDefinition(
            accessor    : $accessor,
            compute     : $compute,
            relation    : $relation,
            resource    : $resource,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : [],
            guards      : $guards,
            transformers: $transformers,
            openApi     : $openApi,
        );
    }

    /**
     * Assert that a schema is a permissive, flagged-undocumented schema that
     * carries no concrete type.
     *
     * @param  \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema  $schema
     * @return void
     */
    private function assertFlagged(OpenApiFieldSchema $schema): void
    {
        self::assertTrue($schema->undocumented);
        self::assertNull($schema->type);
        self::assertNull($schema->format);
    }
}
