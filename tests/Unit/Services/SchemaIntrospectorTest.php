<?php

namespace Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition;
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
     * Test that getColumns returns the cached result on a second call without
     * hitting Schema again.
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
     * Test that getColumns serves the instance cache without consulting the
     * memo cache or the schema again.
     *
     * @return void
     */
    public function testGetColumnsServesInstanceCacheWithoutSchemaLookup(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $first = $introspector->getColumns($model);

        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        Schema::shouldReceive('getColumnListing')
            ->never();

        static::assertSame($first, $introspector->getColumns($model));
    }

    /**
     * Test that an empty column listing is served from the memo cache on a
     * later instance rather than being re-queried every time, since an empty
     * array is a valid cached result.
     *
     * @return void
     */
    public function testGetColumnsCachesEmptyColumnListAcrossInstances(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->andReturn([]);

        $model = new User;

        $first  = (new SchemaIntrospector)->getColumns($model);
        $second = (new SchemaIntrospector)->getColumns($model);

        static::assertSame([], $first);
        static::assertSame([], $second);
    }

    /**
     * Test that getColumns stores the result in the memo cache under a key
     * scoped to the model class.
     *
     * @return void
     */
    public function testGetColumnsStoresResultInMemoCacheUnderModelKey(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $columns = $introspector->getColumns($model);

        $key = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([User::class]);

        static::assertSame($columns, Cache::memo()->get($key));
    }

    /**
     * Test that getColumns keeps the cached columns of different models
     * separate.
     *
     * @return void
     */
    public function testGetColumnsKeepsModelCachesSeparate(): void
    {
        $introspector = new SchemaIntrospector;

        $userColumns = $introspector->getColumns(new User);
        $postColumns = $introspector->getColumns(new Post);

        static::assertSame(Schema::getColumnListing('users'), $userColumns);
        static::assertSame(Schema::getColumnListing('posts'), $postColumns);
        static::assertNotSame($userColumns, $postColumns);
    }

    /**
     * Test that getColumnDefinitions returns one ColumnDefinition per column
     * keyed by column name, carrying its type and nullability.
     *
     * @return void
     */
    public function testGetColumnDefinitionsReturnsDefinitionsKeyedByColumnName(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        static::assertArrayHasKey('id', $definitions);
        static::assertArrayHasKey('email', $definitions);
        static::assertContainsOnlyInstancesOf(ColumnDefinition::class, $definitions);
        static::assertSame('id', $definitions['id']->name);
    }

    /**
     * Test that getColumnDefinitions reports a non-nullable column as not
     * nullable and a nullable column as nullable.
     *
     * @return void
     */
    public function testGetColumnDefinitionsReportsNullability(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        static::assertFalse($definitions['email']->nullable);
        static::assertTrue($definitions['organization_id']->nullable);
    }

    /**
     * Test that getColumnDefinitions normalises the driver type name to
     * lower case.
     *
     * @return void
     */
    public function testGetColumnDefinitionsLowerCasesTypeName(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $typeName = $introspector->getColumnDefinitions($model)['id']->typeName;

        static::assertSame(strtolower($typeName), $typeName);
    }

    /**
     * Test that getColumnDefinitions serves the instance cache without
     * consulting Schema again.
     *
     * @return void
     */
    public function testGetColumnDefinitionsServesInstanceCacheWithoutSchemaLookup(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $first = $introspector->getColumnDefinitions($model);

        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        Schema::shouldReceive('getColumns')
            ->never();

        static::assertSame($first, $introspector->getColumnDefinitions($model));
    }

    /**
     * Test that getColumnDefinitions stores the result in the memo cache
     * under a key scoped to the model class.
     *
     * @return void
     */
    public function testGetColumnDefinitionsStoresResultInMemoCacheUnderModelKey(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        $key = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([User::class]);

        static::assertSame($definitions, Cache::memo()->get($key));
    }

    /**
     * Test that flush clears cached column definitions so the next call
     * re-queries the schema.
     *
     * @return void
     */
    public function testFlushClearsColumnDefinitions(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $original = $introspector->getColumnDefinitions($model);

        static::assertNotEmpty($original);

        $introspector->flush();

        static::assertSame([], $this->getProperty($introspector, 'columnDefinitions'));
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
     * Test that getSearchableColumns ignores table-specific exclusions intended
     * for other tables.
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
     * Test that getSearchableColumns keeps a column when a table-specific
     * exclusion targets another table.
     *
     * @return void
     */
    public function testGetSearchableColumnsKeepsColumnWhenExclusionTargetsAnotherTable(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['posts.name']);

        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertContains('name', $introspector->getSearchableColumns($model));
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
     * Test that isRelation does not attempt to invoke, or log a failure for, a
     * non-existent method.
     *
     * @return void
     */
    public function testIsRelationDoesNotLogForNonExistentMethod(): void
    {
        Log::shouldReceive('error')
            ->never();

        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertFalse($introspector->isRelation('nonExistent', $model));
    }

    /**
     * Test that isRelation returns true for a BelongsTo relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForBelongsToRelation(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertTrue($introspector->isRelation('organization', $model));
    }

    /**
     * Test that isRelation returns true for a HasOne relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForHasOneRelation(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertTrue($introspector->isRelation('profile', $model));
    }

    /**
     * Test that isRelation returns false for a method with a non-relation
     * return type.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForNonRelationReturnType(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            /**
             * A method that returns a string, not a relation.
             *
             * @return string
             */
            public function tags(): string
            {
                return '';
            }
        };

        $introspector = new SchemaIntrospector;

        static::assertFalse($introspector->isRelation('tags', $model));
    }

    /**
     * Test that isRelation returns false for a method without a return type
     * declaration.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForMethodWithoutReturnType(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            // phpcs:disable Squiz.Commenting.FunctionComment.MissingReturn
            /**
             * A method with no return type declaration.
             */
            public function tags() // @phpstan-ignore missingType.return
            {
                return $this;
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.MissingReturn
        };

        $introspector = new SchemaIntrospector;

        static::assertFalse($introspector->isRelation('tags', $model));
    }

    /**
     * Test that isRelation returns true for a union return type that contains a
     * Relation subclass.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForUnionReturnTypeContainingRelation(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            // phpcs:disable Generic.Files.LineLength.TooLong
            /**
             * A method with a union return type containing relation types.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>|\Illuminate\Database\Eloquent\Relations\MorphMany<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function tags(): HasMany|MorphMany // @phpstan-ignore return.unusedType
            {
                return $this->hasMany(Post::class);
            }
            // phpcs:enable Generic.Files.LineLength.TooLong
        };

        $introspector = new SchemaIntrospector;

        static::assertTrue($introspector->isRelation('tags', $model));
    }

    /**
     * Test that isRelation returns false for a union return type with no
     * Relation subclass.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForUnionReturnTypeWithNoRelation(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            /**
             * A method with a union return type containing no relation types.
             *
             * @return int|string
             */
            public function tags(): int|string // @phpstan-ignore return.unusedType (the non-relation union return type is the reflection subject under test)
            {
                return '';
            }
        };

        $introspector = new SchemaIntrospector;

        static::assertFalse($introspector->isRelation('tags', $model));
    }

    /**
     * Test that isRelation returns true for a dynamically registered relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForDynamicRelation(): void
    {
        $property = new \ReflectionProperty(Model::class, 'relationResolvers');
        $original = $property->getValue();

        try {
            User::resolveRelationUsing('dynamicPosts', fn (User $model) => $model->hasMany(Post::class));

            $introspector = new SchemaIntrospector;

            static::assertTrue($introspector->isRelation('dynamicPosts', new User));
        } finally {
            $property->setValue($original);
        }
    }

    /**
     * Test that isRelation returns false for an Eloquent attribute accessor.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForAttributeAccessor(): void
    {
        $introspector = new SchemaIntrospector;
        $model        = new User;

        static::assertFalse($introspector->isRelation('fullLabel', $model));
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
     * Test that the service provider registers SchemaIntrospectionProvider as a
     * singleton bound to SchemaIntrospector.
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

    /**
     * Test that flush clears cached columns so the next getColumns call
     * re-queries the database.
     *
     * @return void
     */
    public function testFlushClearsColumns(): void
    {
        // Arrange
        $introspector = new SchemaIntrospector;
        $model        = new User;

        $originalColumns = $introspector->getColumns($model);

        static::assertNotEmpty($originalColumns);

        // Act
        $introspector->flush();
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('users')
            ->andReturn(['id', 'name', 'extra_column']);

        $refreshedColumns = $introspector->getColumns($model);

        // Assert
        static::assertSame(['id', 'name', 'extra_column'], $refreshedColumns);
        static::assertNotSame($originalColumns, $refreshedColumns);
    }

    /**
     * Test that flush clears cached searchable columns so the next
     * getSearchableColumns call re-computes.
     *
     * @return void
     */
    public function testFlushClearsSearchable(): void
    {
        // Arrange
        Config::set('api-toolkit.repositories.searchable_exclusions', ['password']);

        $introspector = new SchemaIntrospector;
        $model        = new User;

        $originalSearchable = $introspector->getSearchableColumns($model);

        static::assertNotContains('password', $originalSearchable);

        // Act
        $introspector->flush();
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('users')
            ->andReturn(['id', 'name', 'extra_column']);

        Config::set('api-toolkit.repositories.searchable_exclusions', []);

        $refreshedSearchable = $introspector->getSearchableColumns($model);

        // Assert
        static::assertSame(['id', 'name', 'extra_column'], $refreshedSearchable);
        static::assertNotSame($originalSearchable, $refreshedSearchable);
    }

    /**
     * Test that calling flush on a freshly constructed introspector with no
     * prior calls does not throw an exception.
     *
     * @return void
     */
    public function testFlushOnEmptyStateIsHarmless(): void
    {
        $introspector = new SchemaIntrospector;

        $introspector->flush();

        static::assertSame([], $this->getProperty($introspector, 'columns'));
        static::assertSame([], $this->getProperty($introspector, 'searchable'));
    }

    /**
     * Test that resolveRelation returns null and logs a warning when the
     * relation method throws a LogicException.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullAndLogsWarningOnLogicException(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
            /**
             * A relation method that throws a LogicException.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             */
            public function broken(): HasMany
            {
                throw new \LogicException('Test logic failure');
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
        };

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'broken') && str_contains($message, 'Test logic failure'));

        $introspector = new SchemaIntrospector;

        static::assertNull($introspector->resolveRelation('broken', $model));
    }

    /**
     * Test that resolveRelation returns null and logs a warning when the
     * relation method throws a ReflectionException.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullAndLogsWarningOnReflectionException(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
            /**
             * A relation method that throws a ReflectionException.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             */
            public function broken(): HasMany
            {
                throw new \ReflectionException('Test reflection failure');
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
        };

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'broken') && str_contains($message, 'Test reflection failure'));

        $introspector = new SchemaIntrospector;

        static::assertNull($introspector->resolveRelation('broken', $model));
    }

    /**
     * Test that resolveRelation does not catch generic exceptions and allows
     * them to propagate.
     *
     * @return void
     */
    public function testResolveRelationDoesNotCatchGenericExceptions(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'users';

            // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
            /**
             * A relation method that throws a RuntimeException.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             */
            public function broken(): HasMany
            {
                throw new \RuntimeException('Unexpected failure');
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected failure');

        $introspector = new SchemaIntrospector;

        $introspector->resolveRelation('broken', $model);
    }

    /**
     * Test that resolveRelation returns a Relation instance for a dynamically
     * registered relation.
     *
     * @return void
     */
    public function testResolveRelationReturnsDynamicRelationInstance(): void
    {
        $property = new \ReflectionProperty(Model::class, 'relationResolvers');
        $original = $property->getValue();

        try {
            User::resolveRelationUsing('dynamicPosts', fn (User $model) => $model->hasMany(Post::class));

            $introspector = new SchemaIntrospector;

            $relation = $introspector->resolveRelation('dynamicPosts', new User);

            static::assertInstanceOf(HasMany::class, $relation);
        } finally {
            $property->setValue($original);
        }
    }

    /**
     * Test that getDeletedAtColumn returns null for a model that does not use
     * SoftDeletes.
     *
     * @return void
     */
    public function testGetDeletedAtColumnReturnsNullWithoutSoftDeletes(): void
    {
        $introspector = new SchemaIntrospector;

        static::assertNull($introspector->getDeletedAtColumn(new User));
    }

    /**
     * Test that getDeletedAtColumn returns the configured soft-delete column for
     * a model that uses SoftDeletes.
     *
     * @return void
     */
    public function testGetDeletedAtColumnReturnsColumnWithSoftDeletes(): void
    {
        $model = new class extends Model {
            use SoftDeletes;

            /** @var string|null */
            protected $table = 'users';
        };

        $introspector = new SchemaIntrospector;

        static::assertSame('deleted_at', $introspector->getDeletedAtColumn($model));
    }

    /**
     * Test that parentKeysFor returns the foreign key for a BelongsTo relation.
     *
     * @return void
     */
    public function testParentKeysForBelongsToReturnsForeignKey(): void
    {
        $introspector = new SchemaIntrospector;
        $relation     = (new User)->organization();

        $keys = $introspector->parentKeysFor($relation);

        static::assertContains('organization_id', $keys);
    }

    /**
     * Test that parentKeysFor returns the local key for a HasMany relation.
     *
     * @return void
     */
    public function testParentKeysForHasManyReturnsLocalKey(): void
    {
        $introspector = new SchemaIntrospector;
        $relation     = (new User)->posts();

        $keys = $introspector->parentKeysFor($relation);

        static::assertContains('id', $keys);
    }

    /**
     * Test that parentKeysFor returns both the morph type and morph id columns
     * for a MorphTo relation.
     *
     * @return void
     */
    public function testParentKeysForMorphToReturnsTypeAndId(): void
    {
        $morphTo = static::createStub(MorphTo::class);

        $morphTo->method('getForeignKeyName')->willReturn('taggable_id');
        $morphTo->method('getMorphType')->willReturn('taggable_type');

        $introspector = new SchemaIntrospector;

        $keys = $introspector->parentKeysFor($morphTo);

        static::assertContains('taggable_id', $keys);
        static::assertContains('taggable_type', $keys);
        static::assertCount(2, $keys);
    }

    /**
     * Test that parentKeysFor returns an empty array for an unrecognised
     * relation type without throwing.
     *
     * @return void
     */
    public function testParentKeysForUnknownRelationReturnsEmpty(): void
    {
        $unknown = static::createStub(Relation::class);

        $introspector = new SchemaIntrospector;

        static::assertSame([], $introspector->parentKeysFor($unknown));
    }

    /**
     * Test that getColumns registers the MODEL_SCHEMA_COLUMNS key in the
     * metadata key registry.
     *
     * @return void
     */
    public function testGetColumnsRegistersSchemaColumnsKey(): void
    {
        // Arrange
        $registry     = app(MetadataKeyRegistry::class);
        $introspector = new SchemaIntrospector;
        $model        = new User;

        // Act
        $introspector->getColumns($model);

        // Assert
        $expectedKey = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([User::class]);

        static::assertContains($expectedKey, $registry->keys());
    }

    /**
     * Test that getColumnDefinitions registers the MODEL_SCHEMA_COLUMN_DEFINITIONS
     * key in the metadata key registry.
     *
     * @return void
     */
    public function testGetColumnDefinitionsRegistersColumnDefinitionsKey(): void
    {
        // Arrange
        $registry     = app(MetadataKeyRegistry::class);
        $introspector = new SchemaIntrospector;
        $model        = new User;

        // Act
        $introspector->getColumnDefinitions($model);

        // Assert
        $expectedKey = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([User::class]);

        static::assertContains($expectedKey, $registry->keys());
    }

    /**
     * Test that isRelation registers the MODEL_RELATIONS key in the metadata
     * key registry.
     *
     * @return void
     */
    public function testIsRelationRegistersRelationsKey(): void
    {
        // Arrange
        $registry     = app(MetadataKeyRegistry::class);
        $introspector = new SchemaIntrospector;
        $model        = new User;

        // Act
        $introspector->isRelation('posts', $model);

        // Assert
        $expectedKey = CacheKeys::MODEL_RELATIONS->resolveKey([User::class, 'posts']);

        static::assertContains($expectedKey, $registry->keys());
    }
}
