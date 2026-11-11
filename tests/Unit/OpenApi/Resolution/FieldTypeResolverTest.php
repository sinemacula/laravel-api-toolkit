<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
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
     * Test that a declared schema is never overridden by inference.
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
     * Test that a typed non-null column infers a concrete unflagged type.
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
     * Test that a nullable column produces a nullable inferred schema.
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
     * Test that a boolean cast takes precedence over the column type.
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
     * Test that a backed-enum cast references and registers the enum.
     *
     * @return void
     */
    public function testBackedEnumCastResolvesToComponentReference(): void
    {
        $enums    = new EnumSchemaRegistry;
        $resolver = $this->makeResolver([
            'status' => new ColumnDefinition(name: 'status', typeName: 'varchar', nullable: false),
        ], $enums);

        $schema = $resolver->resolve('status', $this->field(), User::class);

        self::assertSame('#/components/schemas/UserStatus', $schema->ref);
        self::assertNull($schema->type);
        self::assertFalse($schema->undocumented);
        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $schema->toArray());
        self::assertSame([UserStatus::class], $enums->classes());
    }

    /**
     * Test that a pure-enum cast references and registers the enum.
     *
     * @return void
     */
    public function testPureEnumCastResolvesToComponentReference(): void
    {
        $model = new class extends Model {
            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            #[\Override]
            protected function casts(): array
            {
                return ['platform' => Platform::class];
            }
        };

        $enums    = new EnumSchemaRegistry;
        $resolver = $this->makeResolver([
            'platform' => new ColumnDefinition(name: 'platform', typeName: 'varchar', nullable: false),
        ], $enums);

        $schema = $resolver->resolve('platform', $this->field(), $model::class);

        self::assertSame(['$ref' => '#/components/schemas/Platform'], $schema->toArray());
        self::assertSame([Platform::class], $enums->classes());
    }

    /**
     * Test that a nullable enum cast widens the reference to an anyOf.
     *
     * @return void
     */
    public function testNullableBackedEnumCastWidensToAnyOf(): void
    {
        $resolver = $this->makeResolver([
            'status' => new ColumnDefinition(name: 'status', typeName: 'varchar', nullable: true),
        ]);

        $schema = $resolver->resolve('status', $this->field(), User::class);

        self::assertTrue($schema->nullable);
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/UserStatus'], ['type' => 'null']]],
            $schema->toArray(),
        );
    }

    /**
     * Test that an aliased scalar is inferred from its presentation key.
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
     * Test that an undeclared accessor field is flagged undocumented.
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
     * Test that a relation field with a child resource is undocumented.
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
     * Test that an undeclared guarded field is flagged undocumented.
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
     * Test that a field with a transformer is flagged undocumented.
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
     * Test that a scalar matching no column is flagged undocumented.
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
     * Test that a column of unknown driver type is flagged undocumented.
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
     * Test that no flagged field ever carries a concrete narrow type.
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
     * Test that a model without a cast map infers from the column type.
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
     * Test that a scalar degrades to its cast without a column definition.
     *
     * @return void
     */
    public function testInfersFromCastWhenColumnAbsent(): void
    {
        $resolver = $this->makeResolver([]);

        $schema = $resolver->resolve('published', $this->field(), Post::class);

        self::assertSame('boolean', $schema->type);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that a scalar without column or mappable cast is undocumented.
     *
     * @return void
     */
    public function testFlaggedWhenColumnAbsentAndNoCast(): void
    {
        $resolver = $this->makeResolver([]);

        $schema = $resolver->resolve('title', $this->field(), Post::class);

        $this->assertFlagged($schema);
    }

    /**
     * Build a resolver whose introspector returns the given columns.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>  $columns
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry|null  $enums
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function makeResolver(array $columns, ?EnumSchemaRegistry $enums = null): FieldTypeResolver
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn($columns);

        return new FieldTypeResolver($introspector, new ColumnTypeMapper, $enums ?? new EnumSchemaRegistry);
    }

    /**
     * Build a compiled field definition, defaulting to a plain scalar.
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
     * Assert that a schema is permissive, flagged undocumented and untyped.
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
