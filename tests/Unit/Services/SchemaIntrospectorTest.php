<?php

namespace Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Services\SchemaIntrospector;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the SchemaIntrospector service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaIntrospector::class)]
class SchemaIntrospectorTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that getColumns returns the column listing from the database.
     *
     * @return void
     */
    public function testGetColumnsReturnsColumnListingFromDatabase(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $columns  = $introspector->getColumns($model);
        $expected = Schema::getColumnListing('users');

        static::assertSame($expected, $columns);
    }

    /**
     * Test that getColumns returns the cached result on a second call
     * without hitting Schema again.
     *
     * @return void
     */
    public function testGetColumnsReturnsCachedResultOnSecondCall(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $first  = $introspector->getColumns($model);
        $second = $introspector->getColumns($model);

        static::assertSame($first, $second);

        $instanceCache = $this->getProperty($introspector, 'columns');

        static::assertArrayHasKey(User::class, $instanceCache);
    }

    /**
     * Test that getSearchableColumns returns columns with exclusions
     * applied.
     *
     * @return void
     */
    public function testGetSearchableColumnsReturnsColumnsWithExclusionsApplied(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['password']);

        $introspector = new SchemaIntrospector;
        $model        = new User;

        $searchable = $introspector->getSearchableColumns($model);

        static::assertNotContains('password', $searchable);
        static::assertContains('name', $searchable);
        static::assertContains('email', $searchable);
    }

    /**
     * Test that getSearchableColumns respects table-specific exclusions.
     *
     * @return void
     */
    public function testGetSearchableColumnsRespectsTableSpecificExclusions(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['users.password']);

        $introspector = new SchemaIntrospector;
        $model        = new User;

        $searchable = $introspector->getSearchableColumns($model);

        static::assertNotContains('password', $searchable);
    }

    /**
     * Test that getSearchableColumns ignores table-specific exclusions
     * intended for other tables.
     *
     * @return void
     */
    public function testGetSearchableColumnsIgnoresTableSpecificExclusionForOtherTables(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['users.password']);

        $introspector = new SchemaIntrospector;
        $model        = new Post;

        $searchable = $introspector->getSearchableColumns($model);
        $allColumns = Schema::getColumnListing('posts');

        static::assertCount(count($allColumns), $searchable);
    }

    /**
     * Test that isSearchable returns true for a searchable column.
     *
     * @return void
     */
    public function testIsSearchableReturnsTrueForSearchableColumn(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertTrue($introspector->isSearchable($model, 'name'));
    }

    /**
     * Test that isSearchable returns false for an excluded column.
     *
     * @return void
     */
    public function testIsSearchableReturnsFalseForExcludedColumn(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['password']);

        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertFalse($introspector->isSearchable($model, 'password'));
    }

    /**
     * Test that isSearchable returns false for a non-existent column.
     *
     * @return void
     */
    public function testIsSearchableReturnsFalseForNonExistentColumn(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertFalse($introspector->isSearchable($model, 'nonexistent'));
    }

    /**
     * Test that isRelation returns true for a valid relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForValidRelation(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertTrue($introspector->isRelation('posts', $model));
    }

    /**
     * Test that isRelation returns false for a non-relation method.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForNonRelationMethod(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertFalse($introspector->isRelation('getKey', $model));
    }

    /**
     * Test that isRelation returns false for a non-existent method.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForNonExistentMethod(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertFalse($introspector->isRelation('nonExistent', $model));
    }

    /**
     * Test that isRelation catches ReflectionException and returns
     * false.
     *
     * @return void
     */
    public function testIsRelationCatchesReflectionExceptionAndReturnsFalse(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            /**
             * A method that throws a ReflectionException when invoked.
             *
             * @return never
             */
            public function brokenRelation(): never
            {
                throw new \ReflectionException('Test reflection failure');
            }
        };

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'brokenRelation') && str_contains($message, 'Test reflection failure'));

        $introspector = new SchemaIntrospector;

        static::assertFalse($introspector->isRelation('brokenRelation', $model));
    }

    /**
     * Test that isRelation catches LogicException and returns false.
     *
     * @return void
     */
    public function testIsRelationCatchesLogicExceptionAndReturnsFalse(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            /**
             * A method that throws a LogicException when invoked.
             *
             * @return never
             */
            public function brokenRelation(): never
            {
                throw new \LogicException('Test logic failure');
            }
        };

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'brokenRelation') && str_contains($message, 'Test logic failure'));

        $introspector = new SchemaIntrospector;

        static::assertFalse($introspector->isRelation('brokenRelation', $model));
    }

    /**
     * Test that isRelation does not catch generic exceptions.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testIsRelationDoesNotCatchGenericExceptions(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            /**
             * A method that throws a RuntimeException when invoked.
             *
             * @return never
             */
            public function brokenRelation(): never
            {
                throw new \RuntimeException('Unexpected failure');
            }
        };

        $introspector = new SchemaIntrospector;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected failure');

        $introspector->isRelation('brokenRelation', $model);
    }

    /**
     * Test that isRelation caches results across calls.
     *
     * @return void
     */
    public function testIsRelationCachesResults(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $first  = $introspector->isRelation('posts', $model);
        $second = $introspector->isRelation('posts', $model);

        static::assertTrue($first);
        static::assertSame($first, $second);
    }

    /**
     * Test that resolveRelation returns a Relation instance for a valid
     * relation.
     *
     * @return void
     */
    public function testResolveRelationReturnsRelationInstanceForValidRelation(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $relation = $introspector->resolveRelation('posts', $model);

        static::assertInstanceOf(HasMany::class, $relation);
    }

    /**
     * Test that resolveRelation returns null for a non-relation method.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullForNonRelation(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertNull($introspector->resolveRelation('getKey', $model));
    }

    /**
     * Test that resolveRelation returns null for a non-existent method.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullForNonExistentMethod(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertNull($introspector->resolveRelation('missing', $model));
    }

    /**
     * Test that the service provider registers SchemaIntrospectionProvider
     * as a singleton bound to SchemaIntrospector.
     *
     * @return void
     */
    public function testServiceProviderRegistersSchemaIntrospectionProviderSingleton(): void
    {
        $first  = app(SchemaIntrospectionProvider::class);
        $second = app(SchemaIntrospectionProvider::class);

        static::assertInstanceOf(SchemaIntrospector::class, $first);
        static::assertSame($first, $second);
    }
}
