<?php

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
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
class FieldColumnMapperTest extends TestCase
{
    /**
     * Reset both static caches before each test to avoid cross-test bleed.
     *
     * @return void
     */
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

        static::assertTrue($map->isMapped('email'));
        static::assertSame(['email'], $map->columnsFor('email'));
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

        static::assertTrue($map->isMapped('full_name'));
        static::assertSame(['first_name', 'last_name'], $map->columnsFor('full_name'));
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

        static::assertFalse($map->isMapped('avatar'));
        static::assertNull($map->columnsFor('avatar'));
    }

    /**
     * Test that a scalar field carrying a guard but no needs is unmapped.
     *
     * @return void
     */
    public function testGuardedScalarWithoutNeedsIsUnmapped(): void
    {
        $schema = $this->makeSchema([
            'secret' => $this->makeDefinition(guards: [fn ($resource, $request) => true]),
        ]);

        $map = FieldColumnMapper::build($schema);

        static::assertFalse($map->isMapped('secret'));
        static::assertNull($map->columnsFor('secret'));
    }

    /**
     * Test that a relation field is unmapped as it contributes no base columns.
     *
     * @return void
     */
    public function testRelationFieldIsUnmapped(): void
    {
        $schema = $this->makeSchema([
            'organization' => $this->makeDefinition(relation: 'organization'),
        ]);

        $map = FieldColumnMapper::build($schema);

        static::assertFalse($map->isMapped('organization'));
        static::assertNull($map->columnsFor('organization'));
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

        static::assertSame($first, $second);
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

        static::assertNotSame($first, $second);
    }

    /**
     * Build a compiled schema from the given field definitions.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>  $fields
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function makeSchema(array $fields): CompiledSchema
    {
        return new CompiledSchema($fields, []);
    }

    /**
     * Build a compiled field definition with the given attributes defaulted.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $relation
     * @param  array<int, string>  $needs
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeDefinition(
        mixed $accessor = null,
        mixed $compute = null,
        ?string $relation = null,
        array $needs = [],
        array $guards = []
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
            transformers: [],
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
