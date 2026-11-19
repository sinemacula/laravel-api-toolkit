<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\TestCase;

/**
 * Tests for the FieldColumnMapper field-column map builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldColumnMapper::class)]
final class FieldColumnMapperTest extends TestCase
{
    /**
     * Reset both static caches before each test to avoid cross-test bleed.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();
    }

    /**
     * Reset both static caches after each test to avoid cross-test bleed.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that a plain scalar field maps to its own column key.
     *
     * @return void
     */
    public function testScalarFieldMapsToItsOwnColumn(): void
    {
        $schema = $this->makeSchema([
            'email' => $this->makeDefinition(),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertTrue($map->isMapped('email'));
        self::assertTrue($map->isInferred('email'));
        self::assertSame(['email'], $map->columnsFor('email'));
    }

    /**
     * Test that an accessor field carrying needs maps to its declared columns.
     *
     * @return void
     */
    public function testNeedsFieldMapsToDeclaredColumns(): void
    {
        $schema = $this->makeSchema([
            'full_name' => $this->makeDefinition(accessor: 'name.full', needs: ['first_name', 'last_name']),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertTrue($map->isMapped('full_name'));
        self::assertFalse($map->isInferred('full_name'));
        self::assertSame(['first_name', 'last_name'], $map->columnsFor('full_name'));
    }

    /**
     * Test that an accessor field without needs is left unmapped.
     *
     * @return void
     */
    public function testAccessorWithoutNeedsIsUnmapped(): void
    {
        $schema = $this->makeSchema([
            'avatar' => $this->makeDefinition(accessor: 'media.avatar'),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertFalse($map->isMapped('avatar'));
        self::assertNull($map->columnsFor('avatar'));
    }

    /**
     * Test that a scalar field carrying a row guard but no needs is unmapped.
     *
     * @return void
     */
    public function testRowGuardedScalarWithoutNeedsIsUnmapped(): void
    {
        $schema = $this->makeSchema([
            'secret' => $this->makeDefinition(guards: [fn ($resource, $request) => true]),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertFalse($map->isMapped('secret'));
        self::assertNull($map->columnsFor('secret'));
    }

    /**
     * Test that a scalar field carrying only a caller guard still reads its own
     * column, so the select stays narrowed.
     *
     * @return void
     */
    public function testCallerGuardedScalarMapsToItsOwnColumn(): void
    {
        $schema = $this->makeSchema([
            'salary' => $this->makeDefinition(callerGuards: [static fn (): bool => false]),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertTrue($map->isMapped('salary'));
        self::assertTrue($map->isInferred('salary'));
        self::assertSame(['salary'], $map->columnsFor('salary'));
    }

    /**
     * Test that a relation rendered by a child resource or a string accessor
     * reads only the related models, so it maps to no column of its own.
     *
     * @return void
     */
    public function testRelationReadingOnlyTheRelatedMapsToNoColumns(): void
    {
        $schema = $this->makeSchema([
            'posts'       => $this->makeDefinition(relation: 'posts'),
            'profile_bio' => $this->makeDefinition(accessor: 'bio', relation: 'profile'),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertSame([], $map->columnsFor('posts'));
        self::assertSame([], $map->columnsFor('profile_bio'));
        self::assertFalse($map->isInferred('posts'));
    }

    /**
     * Test that a relation whose accessor, compute, guard or transformer
     * receives the resource is unmapped.
     *
     * @return void
     */
    public function testRelationReceivingTheResourceIsUnmapped(): void
    {
        $schema = $this->makeSchema([
            'callable'    => $this->makeDefinition(accessor: static fn (): null => null, relation: 'posts'),
            'computed'    => $this->makeDefinition(compute: 'getPosts', relation: 'posts'),
            'guarded'     => $this->makeDefinition(relation: 'posts', guards: [static fn (): bool => true]),
            'transformed' => $this->makeDefinition(relation: 'posts', transformers: [static fn (mixed $value): mixed => $value]),
        ]);

        $map = FieldColumnMapper::build($schema);

        foreach (['callable', 'computed', 'guarded', 'transformed'] as $field) {
            self::assertFalse($map->isMapped($field), $field);
        }
    }

    /**
     * Test that a relation declaring needs() reads the declared columns.
     *
     * @return void
     */
    public function testRelationWithNeedsMapsToItsDeclaredColumns(): void
    {
        $schema = $this->makeSchema([
            'organization' => $this->makeDefinition(relation: 'organization', needs: ['organization_id'], guards: [static fn (): bool => true]),
        ]);

        self::assertSame(['organization_id'], FieldColumnMapper::build($schema)->columnsFor('organization'));
    }

    /**
     * Test that the map declares every field key and, only where the schema
     * declares an aggregate, the aggregates payload.
     *
     * @return void
     */
    public function testDeclaresTheFieldKeysAndTheAggregatesPayload(): void
    {
        $fields = ['name' => $this->makeDefinition()];

        $without = FieldColumnMapper::build($this->makeSchema($fields));
        $with    = FieldColumnMapper::build($this->makeSchema($fields, ['posts_count' => $this->makeAggregate()]));

        self::assertTrue($without->declares('name'));
        self::assertFalse($without->declares('aggregates'));
        self::assertFalse($without->isMapped('aggregates'));
        self::assertFalse($without->declares('_type'));
        self::assertTrue($with->declares('name'));
        self::assertTrue($with->declares('aggregates'));
        self::assertSame([], $with->columnsFor('aggregates'));
    }

    /**
     * Test that the aggregates payload is unmapped once any aggregate carries a
     * guard or transformer, since either receives the resource.
     *
     * @return void
     */
    public function testAggregatesPayloadIsUnmappedWhenAnAggregateReceivesTheResource(): void
    {
        $guarded     = $this->makeAggregate(guards: [static fn (): bool => true]);
        $transformed = $this->makeAggregate(transformers: [static fn (mixed $resource, mixed $value): mixed => $value]);

        foreach ([$guarded, $transformed] as $aggregate) {

            $map = FieldColumnMapper::build($this->makeSchema([], ['plain' => $this->makeAggregate(), 'opaque' => $aggregate]));

            self::assertTrue($map->declares('aggregates'));
            self::assertFalse($map->isMapped('aggregates'));
        }
    }

    /**
     * Test that a needs-carrying plain scalar is declared, not inferred.
     *
     * @return void
     */
    public function testNeedsCarryingScalarIsDeclaredRatherThanInferred(): void
    {
        $schema = $this->makeSchema([
            'title' => $this->makeDefinition(needs: ['title', 'slug']),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertTrue($map->isMapped('title'));
        self::assertFalse($map->isInferred('title'));
        self::assertSame(['title', 'slug'], $map->columnsFor('title'));
    }

    /**
     * Test that the timestamp and date helpers map to their own column, under
     * an alias too.
     *
     * @return void
     */
    public function testTimestampAndDateHelpersMapToTheirOwnColumn(): void
    {
        $map = FieldColumnMapper::for($this->makeResourceClass(Field::set(
            Field::timestamp('created_at'),
            Field::date('dob', 'birthday'),
        )));

        self::assertFalse($map->isInferred('created_at'));
        self::assertSame(['created_at'], $map->columnsFor('created_at'));
        self::assertSame(['dob'], $map->columnsFor('birthday'));
    }

    /**
     * Test that a computed or transformed field without needs is unmapped.
     *
     * @return void
     */
    public function testComputedAndTransformedFieldsWithoutNeedsAreUnmapped(): void
    {
        $schema = $this->makeSchema([
            'computed'    => $this->makeDefinition(compute: 'getComputed'),
            'transformed' => $this->makeDefinition(transformers: [static fn (mixed $value): mixed => $value]),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertFalse($map->isMapped('computed'));
        self::assertFalse($map->isMapped('transformed'));
    }

    /**
     * Test that an unmapped field does not stop later fields being mapped.
     *
     * @return void
     */
    public function testBuildContinuesPastUnmappedFieldsToLaterMappedFields(): void
    {
        $schema = $this->makeSchema([
            'opaque' => $this->makeDefinition(accessor: 'nested.path'),
            'plain'  => $this->makeDefinition(),
        ]);

        $map = FieldColumnMapper::build($schema);

        self::assertFalse($map->isMapped('opaque'));
        self::assertTrue($map->isMapped('plain'));
        self::assertSame(['plain'], $map->columnsFor('plain'));
    }

    /**
     * Test that for() returns the same cached map instance per resource class.
     *
     * @return void
     */
    public function testForCachesMapPerResourceClass(): void
    {
        $resourceClass = $this->makeResourceClass(['name' => []]);

        $first  = FieldColumnMapper::for($resourceClass);
        $second = FieldColumnMapper::for($resourceClass);

        self::assertSame($first, $second);
    }

    /**
     * Test that clearCache() forces for() to rebuild a fresh map instance.
     *
     * @return void
     */
    public function testClearCacheResetsCache(): void
    {
        $resourceClass = $this->makeResourceClass(['name' => []]);

        $first = FieldColumnMapper::for($resourceClass);

        FieldColumnMapper::clearCache();

        $second = FieldColumnMapper::for($resourceClass);

        self::assertNotSame($first, $second);
    }

    /**
     * Build a compiled schema from the given field and aggregate definitions.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>  $fields
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition>  $aggregates
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function makeSchema(array $fields, array $aggregates = []): CompiledSchema
    {
        return new CompiledSchema($fields, $aggregates);
    }

    /**
     * Build a compiled count aggregate with the given guards and transformers.
     *
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function makeAggregate(array $guards = [], array $transformers = []): CompiledAggregateDefinition
    {
        return new CompiledAggregateDefinition('posts_count', 'posts', AggregateFunction::COUNT, null, null, true, $guards, $transformers);
    }

    /**
     * Build a compiled field definition with the given attributes defaulted.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $relation
     * @param  array<int, string>  $needs
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  array<int, callable(mixed): bool>  $callerGuards
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeDefinition(
        mixed $accessor = null,
        mixed $compute = null,
        ?string $relation = null,
        array $needs = [],
        array $guards = [],
        array $transformers = [],
        array $callerGuards = [],
    ): CompiledFieldDefinition {
        return new CompiledFieldDefinition(
            accessor    : $accessor,
            compute     : $compute,
            relation    : $relation,
            resource    : null,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : $needs,
            guards      : $guards,
            transformers: $transformers,
            callerGuards: $callerGuards,
        );
    }

    /**
     * Create an anonymous resource class exposing the given raw schema.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return class-string
     */
    private function makeResourceClass(array $rawSchema): string
    {
        $stub = new class ($rawSchema) {
            /** @var array<string, array<string, mixed>> */
            private static array $schema = [];

            /**
             * @param  array<string, array<string, mixed>>  $schema
             */
            public function __construct(array $schema)
            {
                self::$schema = $schema;
            }

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return self::$schema;
            }
        };

        return $stub::class;
    }
}
