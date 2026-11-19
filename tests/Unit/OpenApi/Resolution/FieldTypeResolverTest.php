<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\AggregateLine;
use Tests\Fixtures\Models\AggregateOwner;
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
     * Test that an uncast timestamp of a timestamped model resolves to a
     * date-time, as Eloquent returns it as a Carbon instance.
     *
     * @return void
     */
    public function testUncastTimestampResolvesToDateTime(): void
    {
        $resolver = $this->makeResolver([
            'created_at' => new ColumnDefinition(name: 'created_at', typeName: 'timestamp', nullable: true),
        ]);

        self::assertSame(['type' => ['string', 'null'], 'format' => 'date-time'], $resolver->resolve('created_at', $this->field(), Post::class)->toArray());
        self::assertSame(['type' => 'string', 'format' => 'date-time'], $this->makeResolver([])->resolve('updated_at', $this->field(), Post::class)->toArray());
    }

    /**
     * Test that a cast on a timestamp wins over its date handling.
     *
     * @return void
     */
    public function testCastOnATimestampWins(): void
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
                return ['created_at' => 'timestamp'];
            }
        };

        $resolver = $this->makeResolver([
            'created_at' => new ColumnDefinition(name: 'created_at', typeName: 'timestamp', nullable: false),
        ]);

        self::assertSame(['type' => 'integer'], $resolver->resolve('created_at', $this->field(), $model::class)->toArray());
    }

    /**
     * Test that an uncast date-time column outside the model's timestamps
     * resolves to a plain string, as the driver returns it.
     *
     * @return void
     */
    public function testUncastDateTimeColumnResolvesToAPlainString(): void
    {
        $model = new class extends Model {
            /** @var bool */
            public $timestamps = false;
        };

        $resolver = $this->makeResolver([
            'created_at' => new ColumnDefinition(name: 'created_at', typeName: 'timestamp', nullable: false),
            'seen_at'    => new ColumnDefinition(name: 'seen_at', typeName: 'datetime', nullable: false),
        ]);

        self::assertSame(['type' => 'string'], $resolver->resolve('seen_at', $this->field(), Post::class)->toArray());
        self::assertSame(['type' => 'string'], $resolver->resolve('created_at', $this->field(), $model::class)->toArray());
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
     * Test that a guarded field is typed from its column, since a guard decides
     * only whether the value is present.
     *
     * @return void
     */
    public function testGuardedFieldIsTypedFromItsColumn(): void
    {
        $resolver = $this->makeResolver([
            'email' => new ColumnDefinition(name: 'email', typeName: 'varchar', nullable: false),
        ]);

        $field = new CompiledFieldDefinition(
            accessor    : null,
            compute     : null,
            relation    : null,
            resource    : null,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : [],
            guards      : [static fn () => true],
            transformers: [],
            callerGuards: [static fn () => true],
        );

        $schema = $resolver->resolve('email', $field, User::class);

        self::assertSame('string', $schema->type);
        self::assertFalse($schema->undocumented);
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
     * Test that count, exists, sum and average take their function's type,
     * whatever the related column.
     *
     * @return void
     */
    public function testColumnlessAndArithmeticAggregatesTakeTheFunctionType(): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);
        $introspector->expects(self::never())->method('resolveRelation');

        $resolver = new FieldTypeResolver($introspector, new ColumnTypeMapper);

        self::assertSame(['type' => 'integer', 'minimum' => 0], $resolver->resolveAggregate($this->aggregate(AggregateFunction::COUNT), AggregateOwner::class));
        self::assertSame(['type' => 'boolean'], $resolver->resolveAggregate($this->aggregate(AggregateFunction::EXISTS), AggregateOwner::class));
        self::assertSame(['type' => ['number', 'null']], $resolver->resolveAggregate($this->aggregate(AggregateFunction::SUM, 'total'), AggregateOwner::class));
        self::assertSame(['type' => ['number', 'null']], $resolver->resolveAggregate($this->aggregate(AggregateFunction::AVG, 'total'), AggregateOwner::class));
    }

    /**
     * Test that a minimum or maximum takes the related column's type, forced
     * nullable since zero related rows yield null.
     *
     * @return void
     */
    public function testExtremesTakeTheRelatedColumnTypeAsNullable(): void
    {
        $enums    = new EnumSchemaRegistry;
        $resolver = $this->makeAggregateResolver([
            'placed_at' => new ColumnDefinition(name: 'placed_at', typeName: 'datetime', nullable: false),
            'status'    => new ColumnDefinition(name: 'status', typeName: 'varchar', nullable: false),
            'quantity'  => new ColumnDefinition(name: 'quantity', typeName: 'integer', nullable: false),
        ], $enums);

        self::assertSame(
            ['type' => ['string', 'null']],
            $resolver->resolveAggregate($this->aggregate(AggregateFunction::MAX, 'placed_at'), AggregateOwner::class),
        );
        self::assertSame(
            ['type' => ['integer', 'null']],
            $resolver->resolveAggregate($this->aggregate(AggregateFunction::MIN, 'quantity'), AggregateOwner::class),
        );
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/UserStatus'], ['type' => 'null']]],
            $resolver->resolveAggregate($this->aggregate(AggregateFunction::MIN, 'status'), AggregateOwner::class),
        );
        self::assertSame(
            ['x-undocumented' => true],
            $resolver->resolveAggregate($this->aggregate(AggregateFunction::MAX, 'unknown'), AggregateOwner::class),
        );
        self::assertContains(UserStatus::class, $enums->classes());
    }

    /**
     * Test that a minimum or maximum whose related model is unknown is flagged
     * undocumented.
     *
     * @return void
     */
    public function testExtremesWithoutAKnownRelatedModelAreUndocumented(): void
    {
        $morph    = self::createStub(MorphTo::class);
        $resolver = $this->makeAggregateResolver([], relation: $morph);
        $missing  = $this->makeAggregateResolver([], relation: null);

        $introspector = self::createMock(SchemaIntrospectionProvider::class);
        $introspector->expects(self::never())->method('resolveRelation');

        $unbound = new FieldTypeResolver($introspector, new ColumnTypeMapper);

        self::assertSame(['x-undocumented' => true], $resolver->resolveAggregate($this->aggregate(AggregateFunction::MAX, 'total'), AggregateOwner::class));
        self::assertSame(['x-undocumented' => true], $missing->resolveAggregate($this->aggregate(AggregateFunction::MAX, 'total'), AggregateOwner::class));
        self::assertSame(['x-undocumented' => true], $unbound->resolveAggregate($this->aggregate(AggregateFunction::MIN, 'total'), \stdClass::class));
        self::assertSame(['x-undocumented' => true], $missing->resolveAggregate($this->aggregate(AggregateFunction::MIN), AggregateOwner::class));
    }

    /**
     * Test that a column with no field is inferred from its column and cast.
     *
     * @return void
     */
    public function testColumnWithoutAFieldIsInferred(): void
    {
        $resolver = $this->makeResolver([
            'title' => new ColumnDefinition(name: 'title', typeName: 'varchar', nullable: true),
        ]);

        self::assertSame(['type' => ['string', 'null']], $resolver->resolveColumn('title', Post::class)->toArray());
        self::assertSame(['type' => 'integer'], $this->makeResolver([])->resolveColumn('id', Post::class)->toArray());
    }

    /**
     * Test that a field emitting its column keeps its declared schema, guarded
     * or not.
     *
     * @return void
     */
    public function testColumnReusesTheDeclarationOfADirectField(): void
    {
        $declared = new OpenApiFieldSchema(type: 'string', format: 'email');
        $resolver = $this->makeResolver([
            'email' => new ColumnDefinition(name: 'email', typeName: 'bigint', nullable: false),
        ]);

        self::assertSame($declared, $resolver->resolveColumn('email', User::class, $this->field(openApi: $declared)));
        self::assertSame($declared, $resolver->resolveColumn('email', User::class, $this->field(guards: [static fn () => true], openApi: $declared)));
    }

    /**
     * Test that a field not emitting its column has its declaration ignored.
     *
     * @return void
     */
    public function testColumnIgnoresTheDeclarationOfAnIndirectField(): void
    {
        $declared = new OpenApiFieldSchema(type: 'string', format: 'email');
        $resolver = $this->makeResolver([
            'email' => new ColumnDefinition(name: 'email', typeName: 'bigint', nullable: false),
        ]);

        $indirect = [
            'accessor'    => $this->field(accessor: 'email', openApi: $declared),
            'compute'     => $this->field(compute: static fn () => 1, openApi: $declared),
            'relation'    => $this->field(relation: 'posts', openApi: $declared),
            'transformer' => $this->field(transformers: [static fn ($resource, $value) => $value], openApi: $declared),
            'undeclared'  => $this->field(),
        ];

        foreach ($indirect as $label => $field) {
            self::assertSame(['type' => 'integer'], $resolver->resolveColumn('email', User::class, $field)->toArray(), $label);
        }
    }

    /**
     * Test that a column read through a model accessor has the field's
     * declaration ignored.
     *
     * @return void
     */
    public function testColumnIgnoresTheDeclarationOfAnAccessedColumn(): void
    {
        $model = new class extends Model {
            /**
             * Get the slug's length.
             *
             * @param  string  $value
             * @return int
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function getSlugAttribute(string $value): int
            {
                return mb_strlen($value);
            }
        };

        $declared = new OpenApiFieldSchema(type: 'integer');
        $resolver = $this->makeResolver([
            'slug'       => new ColumnDefinition(name: 'slug', typeName: 'varchar', nullable: false),
            'full_label' => new ColumnDefinition(name: 'full_label', typeName: 'varchar', nullable: false),
        ]);

        self::assertSame(['type' => 'string'], $resolver->resolveColumn('slug', $model::class, $this->field(openApi: $declared))->toArray());
        self::assertSame(['type' => 'string'], $resolver->resolveColumn('full_label', User::class, $this->field(openApi: $declared))->toArray());
    }

    /**
     * Test that an enum-cast column references and registers the enum.
     *
     * @return void
     */
    public function testEnumCastColumnResolvesToComponentReference(): void
    {
        $enums = new EnumSchemaRegistry;

        $schema = $this->makeResolver([], $enums)->resolveColumn('status', User::class);

        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $schema->toArray());
        self::assertSame([UserStatus::class], $enums->classes());
    }

    /**
     * Build a resolver whose owner relation resolves to the aggregate lines.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>  $columns
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry|null  $enums
     * @param  mixed  $relation
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function makeAggregateResolver(array $columns, ?EnumSchemaRegistry $enums = null, mixed $relation = false): FieldTypeResolver
    {
        if ($relation === false) {
            $relation = self::createStub(HasMany::class);
            $relation->method('getRelated')->willReturn(new AggregateLine);
        }

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn($columns);
        $introspector->method('resolveRelation')->willReturn($relation);

        return new FieldTypeResolver($introspector, new ColumnTypeMapper, $enums ?? new EnumSchemaRegistry);
    }

    /**
     * Build a compiled aggregate over the owner's lines.
     *
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string|null  $column
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function aggregate(AggregateFunction $function, ?string $column = null): CompiledAggregateDefinition
    {
        return new CompiledAggregateDefinition('metric', 'lines', $function, $column, null, false, []);
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
