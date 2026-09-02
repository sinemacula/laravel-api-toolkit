<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
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
final class SchemaIntrospectorTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that getColumns returns the column listing from the database.
     *
     * @return void
     */
    public function testGetColumnsReturnsColumnListingFromDatabase(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $columns  = $introspector->getColumns($model);
        $expected = Schema::getColumnListing('users');

        self::assertSame($expected, $columns);
    }

    /**
     * Test that getColumns returns the cached result on a second call without
     * hitting Schema again.
     *
     * @return void
     */
    public function testGetColumnsReturnsCachedResultOnSecondCall(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $first  = $introspector->getColumns($model);
        $second = $introspector->getColumns($model);

        self::assertSame($first, $second);

        $instanceCache = $this->getProperty($introspector, 'columns');

        self::assertArrayHasKey('testing|' . User::class, $instanceCache);
    }

    /**
     * Test that getColumns serves the instance cache without consulting the
     * memo cache or the schema again.
     *
     * @return void
     */
    public function testGetColumnsServesInstanceCacheWithoutSchemaLookup(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumnListing')
            ->willReturn(['id', 'name']);

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        $first = $introspector->getColumns($model);

        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        self::assertSame($first, $introspector->getColumns($model));
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

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumnListing')
            ->willReturn([]);

        $model = $this->modelReadingFrom($builder);

        $first  = ($this->makeIntrospector())->getColumns($model);
        $second = ($this->makeIntrospector())->getColumns($model);

        self::assertSame([], $first);
        self::assertSame([], $second);
    }

    /**
     * Test that getColumns stores the result in the memo cache under a key
     * scoped to the model class and the connection it was read from.
     *
     * @return void
     */
    public function testGetColumnsStoresResultInMemoCacheUnderModelKey(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $columns = $introspector->getColumns($model);

        $key = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey(['testing', User::class]);

        self::assertSame($columns, Cache::memo()->get($key));
    }

    /**
     * Test that getColumns keeps the cached columns of different models
     * separate.
     *
     * @return void
     */
    public function testGetColumnsKeepsModelCachesSeparate(): void
    {
        $introspector = $this->makeIntrospector();

        $userColumns = $introspector->getColumns(new User);
        $postColumns = $introspector->getColumns(new Post);

        self::assertSame(Schema::getColumnListing('users'), $userColumns);
        self::assertSame(Schema::getColumnListing('posts'), $postColumns);
        self::assertNotSame($userColumns, $postColumns);
    }

    /**
     * Test that the column listing is read from the connection the model itself
     * resolves rather than the default one, and cached under a key naming that
     * connection, so two models of the same class on different connections
     * cannot serve each other's answer.
     *
     * @return void
     */
    public function testGetColumnsReadsTheConnectionTheModelResolves(): void
    {
        $model = $this->modelOnSecondaryConnection();

        self::assertSame(['handle'], ($this->makeIntrospector())->getColumns($model));
        self::assertNotSame(['handle'], Schema::getColumnListing('users'));
        self::assertSame(
            ['handle'],
            Cache::memo()->get(CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey(['secondary', $model::class])),
        );
    }

    /**
     * Test that getColumnDefinitions returns one ColumnDefinition per column
     * keyed by column name, carrying its type and nullability.
     *
     * @return void
     */
    public function testGetColumnDefinitionsReturnsDefinitionsKeyedByColumnName(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        self::assertArrayHasKey('id', $definitions);
        self::assertArrayHasKey('email', $definitions);
        self::assertContainsOnlyInstancesOf(ColumnDefinition::class, $definitions);
        self::assertSame('id', $definitions['id']->name);
    }

    /**
     * Test that getColumnDefinitions reports a non-nullable column as not
     * nullable and a nullable column as nullable.
     *
     * @return void
     */
    public function testGetColumnDefinitionsReportsNullability(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        self::assertFalse($definitions['email']->nullable);
        self::assertTrue($definitions['organization_id']->nullable);
    }

    /**
     * Test that getColumnDefinitions normalises the driver type name to lower
     * case.
     *
     * @return void
     */
    public function testGetColumnDefinitionsLowerCasesTypeName(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $typeName = $introspector->getColumnDefinitions($model)['id']->typeName;

        self::assertSame(strtolower($typeName), $typeName);
    }

    /**
     * Test that getColumnDefinitions serves the instance cache without
     * consulting Schema again.
     *
     * @return void
     */
    public function testGetColumnDefinitionsServesInstanceCacheWithoutSchemaLookup(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([['name' => 'id', 'type_name' => 'integer', 'nullable' => false]]);

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        $first = $introspector->getColumnDefinitions($model);

        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        self::assertSame($first, $introspector->getColumnDefinitions($model));
    }

    /**
     * Test that a fresh introspector serves column definitions from the memo
     * cache populated by an earlier instance, without re-querying the schema.
     *
     * @return void
     */
    public function testGetColumnDefinitionsServesMemoCacheOnFreshInstance(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([['name' => 'id', 'type_name' => 'integer', 'nullable' => false]]);

        $model = $this->modelReadingFrom($builder);

        $first = ($this->makeIntrospector())->getColumnDefinitions($model);

        self::assertNotEmpty($first);

        $second = ($this->makeIntrospector())->getColumnDefinitions($model);

        self::assertEquals($first, $second);
    }

    /**
     * Test that getColumnDefinitions stores the result in the memo cache under
     * a key scoped to the model class and the connection it was read from.
     *
     * @return void
     */
    public function testGetColumnDefinitionsStoresResultInMemoCacheUnderModelKey(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        $key = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey(['testing', User::class]);

        self::assertSame($definitions, Cache::memo()->get($key));
    }

    /**
     * Test that flush clears cached column definitions so the next call
     * re-queries the schema.
     *
     * @return void
     */
    public function testFlushClearsColumnDefinitions(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $original = $introspector->getColumnDefinitions($model);

        self::assertNotEmpty($original);

        $introspector->flush();

        self::assertSame([], $this->getProperty($introspector, 'columnDefinitions'));
    }

    /**
     * Test that getIndexes returns the indexes the connection reports, carrying
     * each composite index in the order the connection lists its columns.
     *
     * @return void
     */
    public function testGetIndexesReturnsTheCatalogueTheConnectionReports(): void
    {
        $indexes = ($this->makeIntrospector())->getIndexes(new User);

        self::assertIsArray($indexes);
        self::assertSame(['status', 'name'], $this->columnsOf($indexes, 'users_status_name_index'));
        self::assertSame(['name'], $this->columnsOf($indexes, 'users_name_index'));
    }

    /**
     * Test that a table the connection reads and finds no index on reports an
     * empty catalogue rather than an unverifiable one, since an empty answer is
     * a real answer a declaration can be refused against.
     *
     * @return void
     */
    public function testGetIndexesReturnsAnEmptyCatalogueForATableCarryingNoIndex(): void
    {
        self::assertSame([], ($this->makeIntrospector())->getIndexes($this->keylessModel()));
    }

    /**
     * Test that the index catalogue is read from the connection the model
     * itself resolves, so a sortable declaration is judged against the table
     * actually behind the model rather than a same-named table on the default
     * connection.
     *
     * @return void
     */
    public function testGetIndexesReadsTheConnectionTheModelResolves(): void
    {
        $indexes = ($this->makeIntrospector())->getIndexes($this->modelOnSecondaryConnection());

        self::assertIsArray($indexes);
        self::assertSame(['users_handle_index'], array_map(static fn ($index): string => $index->name, $indexes));
    }

    /**
     * Test that a connection that cannot be inspected reports null rather than
     * an empty catalogue, so a boot with no database behind it proves nothing
     * instead of proving every table indexless.
     *
     * The two answers must never be conflated: reading the unverifiable one as
     * an empty catalogue turns every such boot into a refusal.
     *
     * @return void
     */
    public function testGetIndexesReportsAnUninspectableConnectionAsUnverifiableRatherThanEmpty(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willThrowException(new \RuntimeException('No database connection'));

        self::assertNull(($this->makeIntrospector())->getIndexes($this->modelReadingFrom($builder)));
    }

    /**
     * Test that a table the connection does not carry reports an unverifiable
     * catalogue rather than an empty one, since an engine answers for a table
     * that is not there with an empty catalogue rather than an error.
     *
     * Reading that silence as an answer would refuse every sortable declaration
     * in an application whose migrations have not run yet.
     *
     * @return void
     */
    public function testGetIndexesReportsATableTheConnectionDoesNotCarryAsUnverifiable(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'never_migrated';
        };

        self::assertSame([], Schema::getColumnListing('never_migrated'));
        self::assertNull(($this->makeIntrospector())->getIndexes($model));
    }

    /**
     * Test that a memoised unverifiable catalogue is served back rather than
     * resolved again, so a boot against a connection that cannot be reached
     * pays one failed read for the model rather than one for every resource
     * mapped to it.
     *
     * @return void
     */
    public function testGetIndexesServesAMemoisedUnverifiableCatalogueWithoutReadingAgain(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willThrowException(new \RuntimeException('No database connection'));

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        self::assertNull($introspector->getIndexes($model));
        self::assertNull($introspector->getIndexes($model));
    }

    /**
     * Test that an unverifiable catalogue is not written to the persistent
     * cache, so a later run against a live connection resolves the real
     * indexes.
     *
     * @return void
     */
    public function testGetIndexesDoesNotCacheAnUnverifiableCatalogue(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willThrowException(new \RuntimeException('No database connection'));

        $model = $this->modelReadingFrom($builder);

        ($this->makeIntrospector())->getIndexes($model);

        self::assertNull(Cache::memo()->get(CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey(['testing', $model::class])));
    }

    /**
     * Test that getIndexes stores the result in the memo cache under a key
     * scoped to the model class and the connection it was read from.
     *
     * @return void
     */
    public function testGetIndexesStoresResultInMemoCacheUnderModelKey(): void
    {
        $indexes = ($this->makeIntrospector())->getIndexes(new User);

        self::assertEquals($indexes, Cache::memo()->get(CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey(['testing', User::class])));
    }

    /**
     * Test that getIndexes registers the MODEL_SCHEMA_INDEXES key in the
     * metadata key registry, so a scoped flush forgets it.
     *
     * @return void
     */
    public function testGetIndexesRegistersSchemaIndexesKey(): void
    {
        $registry = app(MetadataKeyRegistry::class);

        ($this->makeIntrospector())->getIndexes(new User);

        self::assertContains(CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey(['testing', User::class]), $registry->keys());
    }

    /**
     * Test that getIndexes serves the instance cache on a second call without
     * consulting the schema again.
     *
     * @return void
     */
    public function testGetIndexesServesInstanceCacheWithoutSchemaLookup(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willReturn([['name' => 'widgets_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        $first = $introspector->getIndexes($model);

        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        self::assertSame($first, $introspector->getIndexes($model));
    }

    /**
     * Test that an empty catalogue is served from the memo cache on a later
     * instance rather than being read again, since an empty catalogue is a
     * valid cached result and a miss would be read as unverifiable.
     *
     * @return void
     */
    public function testGetIndexesCachesAnEmptyCatalogueAcrossInstances(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willReturn([]);

        $builder->method('getColumnListing')->willReturn(['id']);

        $model = $this->modelReadingFrom($builder);

        self::assertSame([], ($this->makeIntrospector())->getIndexes($model));
        self::assertSame([], ($this->makeIntrospector())->getIndexes($model));
    }

    /**
     * Test that a catalogue entry the connection reports in a shape that cannot
     * be read is passed over, leaving the readable entries behind it.
     *
     * @return void
     */
    public function testGetIndexesPassesOverAnUnreadableCatalogueEntry(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willReturn([
                ['name' => null, 'columns' => ['name'], 'type' => 'btree'],
                ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
            ]);

        $indexes = ($this->makeIntrospector())->getIndexes($this->modelReadingFrom($builder));

        self::assertIsArray($indexes);
        self::assertCount(1, $indexes);
        self::assertSame('users_name_index', $indexes[0]->name);
    }

    /**
     * Test that flush clears cached indexes so the next call reads the
     * catalogue again.
     *
     * @return void
     */
    public function testFlushClearsIndexes(): void
    {
        $introspector = $this->makeIntrospector();

        $introspector->getIndexes(new User);

        self::assertNotSame([], $this->getProperty($introspector, 'indexes'));

        $introspector->flush();

        self::assertSame([], $this->getProperty($introspector, 'indexes'));
    }

    /**
     * Test that isRelation returns true for a valid relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForValidRelation(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertTrue($introspector->isRelation('posts', $model));
    }

    /**
     * Test that isRelation returns false for a non-relation method.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForNonRelationMethod(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertFalse($introspector->isRelation('getKey', $model));
    }

    /**
     * Test that isRelation returns false for a non-existent method.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForNonExistentMethod(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertFalse($introspector->isRelation('nonExistent', $model));
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

        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertFalse($introspector->isRelation('nonExistent', $model));
    }

    /**
     * Test that isRelation returns true for a BelongsTo relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForBelongsToRelation(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertTrue($introspector->isRelation('organization', $model));
    }

    /**
     * Test that isRelation returns true for a HasOne relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForHasOneRelation(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertTrue($introspector->isRelation('profile', $model));
    }

    /**
     * Test that parentKeysFor returns the foreign key for a BelongsTo relation
     * and the local key for a HasOneOrMany relation.
     *
     * @return void
     */
    public function testParentKeysForReturnsRelationParentKeys(): void
    {
        $introspector = $this->makeIntrospector();
        $user         = new User;

        // BelongsTo resolves to the owning foreign key on the child table.
        self::assertSame(['organization_id'], $introspector->parentKeysFor($user->organization()));

        // HasOne and HasMany (HasOneOrMany) resolve to the parent's local key.
        self::assertSame([$user->getKeyName()], $introspector->parentKeysFor($user->posts()));
        self::assertSame([$user->getKeyName()], $introspector->parentKeysFor($user->profile()));
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

        $introspector = $this->makeIntrospector();

        self::assertFalse($introspector->isRelation('tags', $model));
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

            // phpcs:disable Squiz.Commenting.FunctionComment.MissingReturn,SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
            /**
             * A method with no return type declaration.
             */
            public function tags() // @phpstan-ignore missingType.return
            {
                return $this;
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.MissingReturn,SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
        };

        $introspector = $this->makeIntrospector();

        self::assertFalse($introspector->isRelation('tags', $model));
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

        $introspector = $this->makeIntrospector();

        self::assertTrue($introspector->isRelation('tags', $model));
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

        $introspector = $this->makeIntrospector();

        self::assertFalse($introspector->isRelation('tags', $model));
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

            $introspector = $this->makeIntrospector();

            self::assertTrue($introspector->isRelation('dynamicPosts', new User));
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
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertFalse($introspector->isRelation('fullLabel', $model));
    }

    /**
     * Test that isRelation caches results across calls.
     *
     * @return void
     */
    public function testIsRelationCachesResults(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $first  = $introspector->isRelation('posts', $model);
        $second = $introspector->isRelation('posts', $model);

        self::assertTrue($first);
        self::assertSame($first, $second);
    }

    /**
     * Test that isRelation caches its result under the configured time-to-live
     * rather than storing it forever, bounding the relation cache key space.
     *
     * @return void
     */
    public function testIsRelationCachesWithConfiguredTtl(): void
    {
        Config::set('api-toolkit.repositories.relation_cache_ttl', 4321);

        $expectedKey = CacheKeys::MODEL_RELATIONS->resolveKey([User::class, 'posts']);

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('remember')
            ->once()
            ->with($expectedKey, 4321, \Mockery::type(\Closure::class))
            ->andReturn(true);

        Cache::shouldReceive('memo')
            ->once()
            ->andReturn($repository);

        self::assertTrue($this->makeIntrospector()->isRelation('posts', new User));
    }

    /**
     * Test that isRelation falls back to the default day-long time-to-live when
     * the configured value is missing or non-numeric.
     *
     * @return void
     */
    public function testIsRelationFallsBackToDefaultTtlWhenConfigNonNumeric(): void
    {
        Config::set('api-toolkit.repositories.relation_cache_ttl', 'not-a-number');

        $expectedKey = CacheKeys::MODEL_RELATIONS->resolveKey([User::class, 'posts']);

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('remember')
            ->once()
            ->with($expectedKey, 86400, \Mockery::type(\Closure::class))
            ->andReturn(true);

        Cache::shouldReceive('memo')
            ->once()
            ->andReturn($repository);

        self::assertTrue($this->makeIntrospector()->isRelation('posts', new User));
    }

    /**
     * Test that resolveRelation returns a Relation instance for a valid
     * relation.
     *
     * @return void
     */
    public function testResolveRelationReturnsRelationInstanceForValidRelation(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $relation = $introspector->resolveRelation('posts', $model);

        self::assertInstanceOf(HasMany::class, $relation);
    }

    /**
     * Test that resolveRelation returns null for a non-relation method.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullForNonRelation(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertNull($introspector->resolveRelation('getKey', $model));
    }

    /**
     * Test that resolveRelation returns null for a non-existent method.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullForNonExistentMethod(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        self::assertNull($introspector->resolveRelation('missing', $model));
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

        self::assertInstanceOf(SchemaIntrospector::class, $first);
        self::assertSame($first, $second);
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
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::exactly(2))
            ->method('getColumnListing')
            ->with('widgets')
            ->willReturnOnConsecutiveCalls(['id', 'name'], ['id', 'name', 'extra_column']);

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        $originalColumns = $introspector->getColumns($model);

        self::assertNotEmpty($originalColumns);

        // Act
        $introspector->flush();
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $refreshedColumns = $introspector->getColumns($model);

        // Assert
        self::assertSame(['id', 'name', 'extra_column'], $refreshedColumns);
        self::assertNotSame($originalColumns, $refreshedColumns);
    }

    /**
     * Test that calling flush on a freshly constructed introspector with no
     * prior calls does not throw an exception.
     *
     * @return void
     */
    public function testFlushOnEmptyStateIsHarmless(): void
    {
        $introspector = $this->makeIntrospector();

        $introspector->flush();

        self::assertSame([], $this->getProperty($introspector, 'columns'));
        self::assertSame([], $this->getProperty($introspector, 'columnDefinitions'));
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
             *
             * @throws \LogicException
             */
            public function broken(): HasMany
            {
                throw new \LogicException('Test logic failure');
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
        };

        $expectedMessage = 'Failed to resolve relation \'broken\' on ' . $model::class . ': Test logic failure';

        Log::shouldReceive('warning')
            ->once()
            ->with($expectedMessage);

        $introspector = $this->makeIntrospector();

        self::assertNull($introspector->resolveRelation('broken', $model));
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
             *
             * @throws \ReflectionException
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

        $introspector = $this->makeIntrospector();

        self::assertNull($introspector->resolveRelation('broken', $model));
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
             *
             * @throws \RuntimeException
             */
            public function broken(): HasMany
            {
                throw new \RuntimeException('Unexpected failure');
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected failure');

        $introspector = $this->makeIntrospector();

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

            $introspector = $this->makeIntrospector();

            $relation = $introspector->resolveRelation('dynamicPosts', new User);

            self::assertInstanceOf(HasMany::class, $relation);
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
        $introspector = $this->makeIntrospector();

        self::assertNull($introspector->getDeletedAtColumn(new User));
    }

    /**
     * Test that getDeletedAtColumn returns the configured soft-delete column
     * for a model that uses SoftDeletes.
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

        $introspector = $this->makeIntrospector();

        self::assertSame('deleted_at', $introspector->getDeletedAtColumn($model));
    }

    /**
     * Test that parentKeysFor returns the foreign key for a BelongsTo relation.
     *
     * @return void
     */
    public function testParentKeysForBelongsToReturnsForeignKey(): void
    {
        $introspector = $this->makeIntrospector();
        $relation     = (new User)->organization();

        $keys = $introspector->parentKeysFor($relation);

        self::assertContains('organization_id', $keys);
    }

    /**
     * Test that parentKeysFor returns the local key for a HasMany relation.
     *
     * @return void
     */
    public function testParentKeysForHasManyReturnsLocalKey(): void
    {
        $introspector = $this->makeIntrospector();
        $relation     = (new User)->posts();

        $keys = $introspector->parentKeysFor($relation);

        self::assertContains('id', $keys);
    }

    /**
     * Test that parentKeysFor returns both the morph type and morph id columns
     * for a MorphTo relation.
     *
     * @return void
     */
    public function testParentKeysForMorphToReturnsTypeAndId(): void
    {
        $morphTo = self::createStub(MorphTo::class);

        $morphTo->method('getForeignKeyName')->willReturn('taggable_id');
        $morphTo->method('getMorphType')->willReturn('taggable_type');

        $introspector = $this->makeIntrospector();

        $keys = $introspector->parentKeysFor($morphTo);

        self::assertContains('taggable_id', $keys);
        self::assertContains('taggable_type', $keys);
        self::assertCount(2, $keys);
    }

    /**
     * Test that parentKeysFor returns an empty array for an unrecognised
     * relation type without throwing.
     *
     * @return void
     */
    public function testParentKeysForUnknownRelationReturnsEmpty(): void
    {
        $unknown = self::createStub(Relation::class);

        $introspector = $this->makeIntrospector();

        self::assertSame([], $introspector->parentKeysFor($unknown));
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
        $introspector = $this->makeIntrospector();
        $model        = new User;

        // Act
        $introspector->getColumns($model);

        // Assert
        $expectedKey = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey(['testing', User::class]);

        self::assertContains($expectedKey, $registry->keys());
    }

    /**
     * Test that getColumnDefinitions registers the
     * MODEL_SCHEMA_COLUMN_DEFINITIONS key in the metadata key registry.
     *
     * @return void
     */
    public function testGetColumnDefinitionsRegistersColumnDefinitionsKey(): void
    {
        // Arrange
        $registry     = app(MetadataKeyRegistry::class);
        $introspector = $this->makeIntrospector();
        $model        = new User;

        // Act
        $introspector->getColumnDefinitions($model);

        // Assert
        $expectedKey = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey(['testing', User::class]);

        self::assertContains($expectedKey, $registry->keys());
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
        $introspector = $this->makeIntrospector();
        $model        = new User;

        // Act
        $introspector->isRelation('posts', $model);

        // Assert
        $expectedKey = CacheKeys::MODEL_RELATIONS->resolveKey([User::class, 'posts']);

        self::assertContains($expectedKey, $registry->keys());
    }

    /**
     * Test that a fresh introspector serves the full multi-column listing from
     * the memo cache populated by an earlier instance.
     *
     * @return void
     */
    public function testGetColumnsServesFullColumnListFromMemoCacheOnFreshInstance(): void
    {
        $model = new User;

        $first = ($this->makeIntrospector())->getColumns($model);

        self::assertGreaterThan(1, count($first));

        $second = ($this->makeIntrospector())->getColumns($model);

        self::assertSame($first, $second);
    }

    /**
     * Test that getColumnDefinitions lower-cases a driver-reported type name
     * that arrives in mixed case.
     *
     * @return void
     */
    public function testGetColumnDefinitionsLowerCasesDriverReportedTypeName(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([
                ['name' => 'id', 'type_name' => 'INTEGER', 'nullable' => false],
            ]);

        $definitions = ($this->makeIntrospector())->getColumnDefinitions($this->modelReadingFrom($builder));

        self::assertSame('integer', $definitions['id']->typeName);
    }

    /**
     * Test that parentKeysFor returns the parent local key for a MorphOneOrMany
     * relation.
     *
     * @return void
     */
    public function testParentKeysForMorphOneOrManyReturnsLocalKey(): void
    {
        $morphMany = self::createStub(MorphMany::class);

        $morphMany->method('getLocalKeyName')->willReturn('taggable_local_id');

        $introspector = $this->makeIntrospector();

        self::assertSame(['taggable_local_id'], $introspector->parentKeysFor($morphMany));
    }

    /**
     * Test that parentKeysFor drops empty key names and reindexes the survivors
     * into a contiguous list.
     *
     * @return void
     */
    public function testParentKeysForFiltersEmptyKeyNamesAndReindexes(): void
    {
        $morphTo = self::createStub(MorphTo::class);

        $morphTo->method('getForeignKeyName')->willReturn('');
        $morphTo->method('getMorphType')->willReturn('taggable_type');

        $introspector = $this->makeIntrospector();

        self::assertSame(['taggable_type'], $introspector->parentKeysFor($morphTo));
    }

    /**
     * Test that parentKeysFor de-duplicates repeated key names.
     *
     * @return void
     */
    public function testParentKeysForDeduplicatesRepeatedKeyNames(): void
    {
        $morphTo = self::createStub(MorphTo::class);

        $morphTo->method('getForeignKeyName')->willReturn('shared_key');
        $morphTo->method('getMorphType')->willReturn('shared_key');

        $introspector = $this->makeIntrospector();

        self::assertSame(['shared_key'], $introspector->parentKeysFor($morphTo));
    }

    /**
     * Test that getColumns degrades to an empty listing when the schema lookup
     * fails, as happens when no database connection is available, rather than
     * letting the failure propagate.
     *
     * @return void
     */
    public function testGetColumnsDegradesToEmptyWhenConnectionUnavailable(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumnListing')
            ->willThrowException(new \RuntimeException('No database connection'));

        self::assertSame([], ($this->makeIntrospector())->getColumns($this->modelReadingFrom($builder)));
    }

    /**
     * Test that a failed getColumns lookup does not write the empty result to
     * the persistent cache, so a later run with a live connection is able to
     * resolve the real columns.
     *
     * @return void
     */
    public function testGetColumnsDoesNotCacheEmptyResultOnFailure(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumnListing')
            ->willThrowException(new \RuntimeException('No database connection'));

        $model = $this->modelReadingFrom($builder);

        ($this->makeIntrospector())->getColumns($model);

        $key = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey(['testing', $model::class]);

        self::assertNull(Cache::memo()->get($key));
    }

    /**
     * Test that getColumnDefinitions degrades to an empty set when the schema
     * lookup fails, as happens when no database connection is available, rather
     * than letting the failure propagate.
     *
     * @return void
     */
    public function testGetColumnDefinitionsDegradesToEmptyWhenConnectionUnavailable(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willThrowException(new \RuntimeException('No database connection'));

        self::assertSame([], ($this->makeIntrospector())->getColumnDefinitions($this->modelReadingFrom($builder)));
    }

    /**
     * Test that a failed getColumnDefinitions lookup does not write the empty
     * result to the persistent cache, so a later run with a live connection is
     * able to resolve the real definitions.
     *
     * @return void
     */
    public function testGetColumnDefinitionsDoesNotCacheEmptyResultOnFailure(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willThrowException(new \RuntimeException('No database connection'));

        $model = $this->modelReadingFrom($builder);

        ($this->makeIntrospector())->getColumnDefinitions($model);

        $key = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey(['testing', $model::class]);

        self::assertNull(Cache::memo()->get($key));
    }

    /**
     * Build a model backed by the keyless staging table, which carries no
     * primary key and no index.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function keylessModel(): Model
    {
        return new class extends Model {
            /** @var string|null */
            protected $table = 'import_rows';
        };
    }

    /**
     * Return the columns the named index covers, or null when the catalogue
     * carries no index of that name.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @param  string  $name
     * @return array<int, string>|null
     */
    private function columnsOf(array $indexes, string $name): ?array
    {
        foreach ($indexes as $index) {

            if ($index->name === $name) {
                return $index->columns;
            }
        }

        return null;
    }

    /**
     * Build a model bound to a second connection carrying a table of the same
     * name as the default one, so a read that ignores the model's connection
     * resolves the wrong catalogue rather than failing outright.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelOnSecondaryConnection(): Model
    {
        Config::set('database.connections.secondary', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        Schema::connection('secondary')->create('users', function (Blueprint $table): void {
            $table->string('handle');
            $table->index('handle', 'users_handle_index');
        });

        return new class extends Model {
            /** @var string|\UnitEnum|null */
            protected $connection = 'secondary';

            /** @var string|null */
            protected $table = 'users';
        };
    }

    /**
     * Build a model whose connection reads its catalogue from the given schema
     * builder, so a test can pin what the connection reports and how often it
     * is asked.
     *
     * @param  \Illuminate\Database\Schema\Builder&\PHPUnit\Framework\MockObject\MockObject  $builder
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelReadingFrom(MockObject&SchemaBuilder $builder): Model
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($builder);

        $model = new class extends Model {
            /** @var string|null */
            protected $table = 'widgets';

            /** @var \Illuminate\Database\Connection|null The connection this model reads its schema from */
            public ?Connection $reader = null;

            /**
             * Return the connection the model reads its schema from.
             *
             * @return \Illuminate\Database\Connection
             */
            #[\Override]
            public function getConnection(): Connection
            {
                assert($this->reader instanceof Connection);

                return $this->reader;
            }
        };

        $model->reader = $connection;

        return $model;
    }

    /**
     * Resolve a schema introspector with its dependencies wired from the
     * container.
     *
     * @return \SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector
     */
    private function makeIntrospector(): SchemaIntrospector
    {
        assert($this->app !== null);

        return $this->app->make(SchemaIntrospector::class);
    }
}
