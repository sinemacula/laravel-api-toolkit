<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIdentity;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Project;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\TenantWidget;
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
     * Test that the columns and their definitions share one catalogue read.
     *
     * @return void
     */
    public function testColumnsAndDefinitionsShareOneCatalogueRead(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([
                ['name' => 'id', 'type_name' => 'integer', 'nullable' => false],
                ['name' => 'name', 'type_name' => 'varchar', 'nullable' => true],
            ]);

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        self::assertSame(['id', 'name'], $introspector->getColumns($model));
        self::assertSame(['id', 'name'], array_keys($introspector->getColumnDefinitions($model)));
        self::assertSame(['id', 'name'], ($this->makeIntrospector())->getColumns($model));
    }

    /**
     * Test that getColumns serves the instance cache before the memo or schema.
     *
     * @return void
     */
    public function testGetColumnsServesInstanceCacheWithoutSchemaLookup(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([['name' => 'id', 'type_name' => 'integer', 'nullable' => false]]);

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        $first = $introspector->getColumns($model);

        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        self::assertSame($first, $introspector->getColumns($model));
    }

    /**
     * Test that pivots of one class over different tables are each read,
     * memoised and stored under their own table.
     *
     * @return void
     */
    public function testPivotsOfOneClassOverDifferentTablesAreReadAndStoredApart(): void
    {
        $introspector = $this->makeIntrospector();
        $posts        = (new Post)->tags()->newPivot();
        $projects     = (new Project)->tags()->newPivot();
        $identity     = SchemaIdentity::of(DB::connection('testing'));

        self::assertSame($posts::class, $projects::class);
        self::assertSame(['post_id', 'tag_id', 'role', 'tagged_at'], $introspector->getColumns($posts));
        self::assertSame(['project_id', 'tag_id'], $introspector->getColumns($projects));
        self::assertSame(['project_id', 'tag_id'], array_keys($introspector->getColumnDefinitions($projects)));
        self::assertContains(['post_id', 'tag_id'], array_column($introspector->getIndexes($posts) ?? [], 'columns'));
        self::assertNotContains(['post_id', 'tag_id'], array_column($introspector->getIndexes($projects) ?? [], 'columns'));
        self::assertSame(['post_id', 'tag_id', 'role', 'tagged_at'], $this->storedColumnNames($this->columnDefinitionsKey($identity, $posts)));
        self::assertSame(['project_id', 'tag_id'], $this->storedColumnNames($this->columnDefinitionsKey($identity, $projects)));
    }

    /**
     * Test that a stored empty set of definitions is read as a miss.
     *
     * @return void
     */
    public function testGetColumnsReadsAStoredEmptySetAsAMiss(): void
    {
        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class, 'users']));

        Cache::memo()->rememberForever($key, fn (): array => []);

        self::assertSame(Schema::getColumnListing('users'), $this->makeIntrospector()->getColumns(new User));
    }

    /**
     * Test that a model on an unbuildable connection degrades to nothing.
     *
     * @return void
     */
    public function testAModelOnAnUnbuildableConnectionDegradesToNothing(): void
    {
        $model = new #[Table('widgets')] class extends Model {
            /** @var string|\UnitEnum|null */
            protected $connection = 'never_configured';
        };

        $introspector = $this->makeIntrospector();

        self::assertSame([], $introspector->getColumns($model));
        self::assertSame([], $introspector->getColumnDefinitions($model));
        self::assertNull($introspector->getIndexes($model));
    }

    /**
     * Test that the schema is keyed by the model's connection object.
     *
     * A tenancy resolver may reuse one name for another tenant's connection.
     *
     * @return void
     */
    public function testTheSchemaIsKeyedByTheConnectionTheModelReadsThrough(): void
    {
        $databaseA = $this->tenantDatabase(static function (Blueprint $table): void {
            $table->string('alpha');
        });

        $databaseB = $this->tenantDatabase(static function (Blueprint $table): void {
            $table->string('beta');
        });

        $introspector = $this->makeIntrospector();

        $modelA = $this->modelOn($this->standaloneTenantConnection($databaseA));
        $modelB = $this->modelOn($this->standaloneTenantConnection($databaseB));

        self::assertSame(['alpha'], $introspector->getColumns($modelA));
        self::assertSame(['beta'], $introspector->getColumns($modelB));
        self::assertSame(['beta'], array_keys($introspector->getColumnDefinitions($modelB)));
        self::assertSame(['alpha'], array_keys($introspector->getColumnDefinitions($modelA)));
    }

    /**
     * Test that getColumns keeps different models' columns separate.
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
     * Test that columns are read and cached per the model's own connection.
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
            $this->storedColumnNames(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of(DB::connection('secondary')), $model::class, $model->getTable()])),
        );
    }

    /**
     * Test that a connection repointed mid-job reads the new tenant's schema.
     *
     * @return void
     */
    public function testATenantSwitchInsideOneJobReadsTheNewTenantsSchema(): void
    {
        $tenantA = $this->tenantDatabase(static function (Blueprint $table): void {
            $table->string('alpha');
        });

        $tenantB = $this->tenantDatabase(static function (Blueprint $table): void {
            $table->string('beta');
        });

        $introspector = $this->makeIntrospector();

        $this->switchTenant(['database' => $tenantA]);
        $identityA = SchemaIdentity::of(DB::connection('tenant'));

        self::assertSame(['alpha'], $introspector->getColumns($this->tenantModel()));
        self::assertSame(['alpha'], array_keys($introspector->getColumnDefinitions($this->tenantModel())));

        $this->switchTenant(['database' => $tenantB]);
        $identityB = SchemaIdentity::of(DB::connection('tenant'));

        self::assertSame(['beta'], $introspector->getColumns($this->tenantModel()));
        self::assertSame(['beta'], array_keys($introspector->getColumnDefinitions($this->tenantModel())));

        self::assertNotSame($identityA, $identityB);
        self::assertSame(['alpha'], $this->storedColumnNames($this->columnDefinitionsKey($identityA, $this->tenantModel())));
        self::assertSame(['beta'], $this->storedColumnNames($this->columnDefinitionsKey($identityB, $this->tenantModel())));
    }

    /**
     * Provide ways to repoint a connection keeping its name and database.
     *
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function schemaRepointings(): iterable
    {
        yield 'table prefix' => [['prefix' => 'other_']];
        yield 'search path' => [['search_path' => 'other']];
    }

    /**
     * Test that a changed prefix or search path keeps its own cache entries.
     *
     * @param  array<string, string>  $change
     * @return void
     */
    #[DataProvider('schemaRepointings')]
    public function testTheSameConnectionUnderAnotherSchemaKeepsSeparateEntries(array $change): void
    {
        $database = $this->tenantDatabase(static function (Blueprint $table): void {
            $table->string('alpha');
        });

        $introspector = $this->makeIntrospector();

        $this->switchTenant(['database' => $database]);
        $introspector->getColumns($this->tenantModel());
        $introspector->getIndexes($this->tenantModel());
        $before = SchemaIdentity::of(DB::connection('tenant'));

        $this->switchTenant(['database' => $database, ...$change]);
        $introspector->getColumns($this->tenantModel());
        $introspector->getIndexes($this->tenantModel());
        $after = SchemaIdentity::of(DB::connection('tenant'));

        $columns = $this->getProperty($introspector, 'columnDefinitions');
        $indexes = $this->getProperty($introspector, 'indexes');

        self::assertIsArray($columns);
        self::assertIsArray($indexes);
        self::assertNotSame($before, $after);
        self::assertCount(2, $columns);
        self::assertCount(2, $indexes);

        foreach ([$before, $after] as $identity) {
            self::assertArrayHasKey($identity . '|' . $this->tenantModel()::class . '|' . $this->tenantModel()->getTable(), $columns);
            self::assertSame(['alpha'], $this->storedColumnNames($this->columnDefinitionsKey($identity, $this->tenantModel())));
        }
    }

    /**
     * Test that getColumnDefinitions returns definitions keyed by column name.
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
     * Test that getColumnDefinitions reports each column's nullability.
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
     * Test that getColumnDefinitions lower-cases the driver type name.
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
     * Test that getColumnDefinitions serves the instance cache.
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

        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        self::assertSame($first, $introspector->getColumnDefinitions($model));
    }

    /**
     * Test that a fresh introspector serves definitions from the memo cache.
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
     * Test that an empty set of column definitions is not stored.
     *
     * @return void
     */
    public function testGetColumnDefinitionsDoesNotStoreAnEmptySet(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::exactly(2))
            ->method('getColumns')
            ->willReturnOnConsecutiveCalls([], [['name' => 'id', 'type_name' => 'integer', 'nullable' => false]]);

        $model = $this->modelReadingFrom($builder);

        self::assertSame([], ($this->makeIntrospector())->getColumnDefinitions($model));

        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of($model->getConnection()), $model::class, $model->getTable()]));

        self::assertNull(Cache::memo()->get($key));
        self::assertSame(['id'], array_keys(($this->makeIntrospector())->getColumnDefinitions($model)));
    }

    /**
     * Test that getColumnDefinitions memoises rows under a scoped key.
     *
     * @return void
     */
    public function testGetColumnDefinitionsStoresResultInMemoCacheUnderModelKey(): void
    {
        $introspector = $this->makeIntrospector();
        $model        = new User;

        $definitions = $introspector->getColumnDefinitions($model);

        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class, 'users']));

        self::assertSame(array_map(static fn (ColumnDefinition $definition): array => $definition->toArray(), array_values($definitions)), Cache::memo()->get($key));
    }

    /**
     * Test that flush clears cached column definitions.
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
     * Test that getIndexes returns composite indexes in reported column order.
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
     * Test that a table with no index reports an empty, verifiable catalogue.
     *
     * @return void
     */
    public function testGetIndexesReturnsAnEmptyCatalogueForATableCarryingNoIndex(): void
    {
        self::assertSame([], ($this->makeIntrospector())->getIndexes($this->keylessModel()));
    }

    /**
     * Test that the index catalogue is read from the model's own connection.
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
     * Test that an uninspectable connection reports a null catalogue.
     *
     * @return void
     */
    public function testGetIndexesReportsAnUninspectableConnectionAsUnverifiableRatherThanEmpty(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willThrowException(new \RuntimeException('No database connection'));

        self::assertNull(($this->makeIntrospector())->getIndexes($this->modelReadingFrom($builder)));
    }

    /**
     * Test that a missing table reports an unverifiable catalogue.
     *
     * Engines answer a missing table with an empty catalogue, not an error.
     *
     * @return void
     */
    public function testGetIndexesReportsATableTheConnectionDoesNotCarryAsUnverifiable(): void
    {
        $model = new #[Table('never_migrated')] class extends Model {};

        self::assertSame([], Schema::getColumnListing('never_migrated'));
        self::assertNull(($this->makeIntrospector())->getIndexes($model));
    }

    /**
     * Test that a memoised unverifiable catalogue is served back.
     *
     * @return void
     */
    public function testGetIndexesServesAMemoisedUnverifiableCatalogueWithoutReadingAgain(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

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
     * Test that an unverifiable catalogue is not persisted.
     *
     * @return void
     */
    public function testGetIndexesDoesNotCacheAnUnverifiableCatalogue(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willThrowException(new \RuntimeException('No database connection'));

        $model = $this->modelReadingFrom($builder);

        ($this->makeIntrospector())->getIndexes($model);

        $key = CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey([SchemaIdentity::of($model->getConnection()), $model::class, $model->getTable()]);

        self::assertNull(Cache::memo()->get($this->metadataStorageKey($key)));
    }

    /**
     * Test that getIndexes memoises under a model- and connection-scoped key.
     *
     * @return void
     */
    public function testGetIndexesStoresResultInMemoCacheUnderModelKey(): void
    {
        $indexes = ($this->makeIntrospector())->getIndexes(new User);

        self::assertIsArray($indexes);

        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class, 'users']));

        self::assertSame(array_map(static fn (IndexDefinition $index): array => $index->toArray(), $indexes), Cache::memo()->get($key));
    }

    /**
     * Test that a second getIndexes call is served from the instance cache.
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

        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        self::assertSame($first, $introspector->getIndexes($model));
    }

    /**
     * Test that an empty catalogue is served from the memo cache.
     *
     * A miss would otherwise be read as unverifiable.
     *
     * @return void
     */
    public function testGetIndexesCachesAnEmptyCatalogueAcrossInstances(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willReturn([]);

        $builder->method('getColumns')->willReturn([['name' => 'id', 'type_name' => 'integer', 'nullable' => false]]);

        $model = $this->modelReadingFrom($builder);

        self::assertSame([], ($this->makeIntrospector())->getIndexes($model));
        self::assertSame([], ($this->makeIntrospector())->getIndexes($model));
    }

    /**
     * Test that an unreadable catalogue entry is skipped, keeping the rest.
     *
     * @return void
     */
    public function testGetIndexesPassesOverAnUnreadableCatalogueEntry(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

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
     * Test that flush clears cached indexes.
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
     * Test that isRelation neither invokes nor logs for a missing method.
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
     * Test that parentKeysFor resolves BelongsTo and HasOneOrMany keys.
     *
     * @return void
     */
    public function testParentKeysForReturnsRelationParentKeys(): void
    {
        $introspector = $this->makeIntrospector();
        $user         = new User;

        self::assertSame(['organization_id'], $introspector->parentKeysFor($user->organization()));

        self::assertSame([$user->getKeyName()], $introspector->parentKeysFor($user->posts()));
        self::assertSame([$user->getKeyName()], $introspector->parentKeysFor($user->profile()));
    }

    /**
     * Test that isRelation returns false for a non-relation return type.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForNonRelationReturnType(): void
    {
        $model = new #[Table('users')] class extends Model {
            /**
             * A method that returns a string, not a relation.
             *
             * @return string
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
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
     * Test that isRelation returns false for a method with no return type.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForMethodWithoutReturnType(): void
    {
        $model = new #[Table('users')] class extends Model {
            // phpcs:disable Squiz.Commenting.FunctionComment.MissingReturn,SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
            /**
             * A method with no return type declaration.
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
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
     * Test that isRelation returns true for a union containing a Relation.
     *
     * @return void
     */
    public function testIsRelationReturnsTrueForUnionReturnTypeContainingRelation(): void
    {
        $model = new #[Table('users')] class extends Model {
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
     * Test that isRelation returns false for a union with no Relation.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseForUnionReturnTypeWithNoRelation(): void
    {
        $model = new #[Table('users')] class extends Model {
            /**
             * A method with a union return type containing no relation types.
             *
             * @return int|string
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function tags(): int|string // @phpstan-ignore return.unusedType
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
            $property->setValue(null, $original);
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
     * Test that isRelation answers from code alone, never the cache store.
     *
     * @return void
     */
    public function testIsRelationDoesNotTouchTheCacheStore(): void
    {
        Cache::shouldReceive('memo')->never();

        $introspector = $this->makeIntrospector();

        self::assertTrue($introspector->isRelation('posts', new User));
        self::assertFalse($introspector->isRelation('getKey', new User));
        self::assertFalse($introspector->isRelation('nonExistent', new User));
    }

    /**
     * Test that isRelation memoises a method's answer for the worker, under the
     * class and the lowercased method name.
     *
     * @return void
     */
    public function testIsRelationMemoisesAMethodAnswerAcrossInstances(): void
    {
        $this->makeIntrospector()->isRelation('Posts', new User);
        $this->makeIntrospector()->isRelation('getKey', new User);

        self::assertSame([User::class . '::posts' => true, User::class . '::getkey' => false], $this->getStaticProperty(SchemaIntrospector::class, 'relationMethods'));

        $this->setStaticProperty(SchemaIntrospector::class, 'relationMethods', [User::class . '::posts' => false]);

        self::assertFalse($this->makeIntrospector()->isRelation('posts', new User));
    }

    /**
     * Test that a key naming no method is not memoised, so client input cannot
     * grow the memo.
     *
     * @return void
     */
    public function testIsRelationDoesNotMemoiseAKeyNamingNoMethod(): void
    {
        $introspector = $this->makeIntrospector();

        foreach (['nonExistent', 'name', 'email'] as $key) {
            self::assertFalse($introspector->isRelation($key, new User));
        }

        self::assertSame([], $this->getStaticProperty(SchemaIntrospector::class, 'relationMethods'));
    }

    /**
     * Test that a relation registered after a first miss is seen.
     *
     * @return void
     */
    public function testIsRelationSeesARelationRegisteredAfterAMiss(): void
    {
        $property = new \ReflectionProperty(Model::class, 'relationResolvers');
        $original = $property->getValue();

        $introspector = $this->makeIntrospector();

        try {
            self::assertFalse($introspector->isRelation('latePosts', new User));

            User::resolveRelationUsing('latePosts', fn (User $model) => $model->hasMany(Post::class));

            self::assertTrue($introspector->isRelation('latePosts', new User));
        } finally {
            $property->setValue(null, $original);
        }
    }

    /**
     * Test that a lifecycle flush keeps the relation answers, which only
     * clearCache forgets.
     *
     * @return void
     */
    public function testRelationAnswersOutliveAFlushUntilCleared(): void
    {
        $introspector = $this->makeIntrospector();
        $introspector->isRelation('posts', new User);

        $introspector->flush();

        self::assertSame([User::class . '::posts' => true], $this->getStaticProperty(SchemaIntrospector::class, 'relationMethods'));

        SchemaIntrospector::clearCache();

        self::assertSame([], $this->getStaticProperty(SchemaIntrospector::class, 'relationMethods'));
    }

    /**
     * Test that resolveRelation returns a Relation for a valid relation.
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
     * Test that the provider binds SchemaIntrospectionProvider as a singleton.
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
     * Test that flush clears cached columns.
     *
     * @return void
     */
    public function testFlushClearsColumns(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::exactly(2))
            ->method('getColumns')
            ->with('widgets')
            ->willReturnOnConsecutiveCalls(
                [['name' => 'id', 'type_name' => 'integer', 'nullable' => false]],
                [['name' => 'id', 'type_name' => 'integer', 'nullable' => false], ['name' => 'extra_column', 'type_name' => 'varchar', 'nullable' => true]],
            );

        $introspector = $this->makeIntrospector();
        $model        = $this->modelReadingFrom($builder);

        $originalColumns = $introspector->getColumns($model);

        self::assertNotEmpty($originalColumns);

        $introspector->flush();
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $refreshedColumns = $introspector->getColumns($model);

        self::assertSame(['id', 'extra_column'], $refreshedColumns);
        self::assertNotSame($originalColumns, $refreshedColumns);
    }

    /**
     * Test that flushing a fresh introspector does not throw.
     *
     * @return void
     */
    public function testFlushOnEmptyStateIsHarmless(): void
    {
        $introspector = $this->makeIntrospector();

        $introspector->flush();

        self::assertSame([], $this->getProperty($introspector, 'columnDefinitions'));
        self::assertSame([], $this->getProperty($introspector, 'indexes'));
    }

    /**
     * Test that resolveRelation returns null and warns on a LogicException.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullAndLogsWarningOnLogicException(): void
    {
        $model = new #[Table('users')] class extends Model {
            // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
            /**
             * A relation method that throws a LogicException.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             *
             * @throws \LogicException
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
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
     * Test that resolveRelation returns null and warns on ReflectionException.
     *
     * @return void
     */
    public function testResolveRelationReturnsNullAndLogsWarningOnReflectionException(): void
    {
        $model = new #[Table('users')] class extends Model {
            // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
            /**
             * A relation method that throws a ReflectionException.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             *
             * @throws \ReflectionException
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
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
     * Test that resolveRelation lets generic exceptions propagate.
     *
     * @return void
     */
    public function testResolveRelationDoesNotCatchGenericExceptions(): void
    {
        $model = new #[Table('users')] class extends Model {
            // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
            /**
             * A relation method that throws a RuntimeException.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             *
             * @throws \RuntimeException
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
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
     * Test that resolveRelation resolves a dynamically registered relation.
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
            $property->setValue(null, $original);
        }
    }

    /**
     * Test that getDeletedAtColumn returns null without SoftDeletes.
     *
     * @return void
     */
    public function testGetDeletedAtColumnReturnsNullWithoutSoftDeletes(): void
    {
        $introspector = $this->makeIntrospector();

        self::assertNull($introspector->getDeletedAtColumn(new User));
    }

    /**
     * Test that getDeletedAtColumn returns the configured soft-delete column.
     *
     * @return void
     */
    public function testGetDeletedAtColumnReturnsColumnWithSoftDeletes(): void
    {
        $model = new #[Table('users')] class extends Model {
            use SoftDeletes;
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

        self::assertSame(['organization_id'], $introspector->parentKeysFor($relation));
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

        self::assertSame(['id'], $introspector->parentKeysFor($relation));
    }

    /**
     * Test that parentKeysFor returns both morph columns for MorphTo.
     *
     * @return void
     */
    public function testParentKeysForMorphToReturnsTypeAndId(): void
    {
        $morphTo = self::createStub(MorphTo::class);

        $morphTo->method('getForeignKeyName')->willReturn('taggable_id');
        $morphTo->method('getMorphType')->willReturn('taggable_type');

        $introspector = $this->makeIntrospector();

        self::assertSame(['taggable_id', 'taggable_type'], $introspector->parentKeysFor($morphTo));
    }

    /**
     * Test that parentKeysFor returns null for an unrecognised relation class.
     *
     * @return void
     */
    public function testParentKeysForUnknownRelationReturnsNull(): void
    {
        self::assertNull($this->makeIntrospector()->parentKeysFor(self::createStub(Relation::class)));
    }

    /**
     * Test that parentKeysFor returns the custom parent key of a
     * belongs-to-many and the custom local key of a has-many-through.
     *
     * @return void
     */
    public function testParentKeysForPivotAndThroughRelationsReturnTheirCustomKeys(): void
    {
        $model = new #[Table('organizations')] class extends Model {
            /**
             * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Tests\Fixtures\Models\Tag, $this>
             */
            public function tags(): BelongsToMany
            {
                return $this->belongsToMany(Tag::class, 'organization_tag', 'organization_slug', 'tag_id', 'slug');
            }

            /**
             * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough<\Tests\Fixtures\Models\Post, \Tests\Fixtures\Models\User, $this>
             */
            public function posts(): HasManyThrough
            {
                return $this->hasManyThrough(Post::class, User::class, 'organization_slug', 'user_id', 'slug');
            }
        };

        $introspector = $this->makeIntrospector();

        self::assertSame(['slug'], $introspector->parentKeysFor($model->tags()));
        self::assertSame(['slug'], $introspector->parentKeysFor($model->posts()));
        self::assertSame(['id'], $introspector->parentKeysFor((new Note)->labels()));
    }

    /**
     * Test that relatedKeysFor returns the related-side columns each relation
     * matches its results on.
     *
     * @return void
     */
    public function testRelatedKeysForReturnsTheColumnsEachRelationMatchesOn(): void
    {
        $model = new #[Table('posts')] class extends Model {
            /**
             * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Organization, $this>
             */
            public function organization(): BelongsTo
            {
                return $this->belongsTo(Organization::class, 'organization_slug', 'slug');
            }
        };

        $introspector = $this->makeIntrospector();

        self::assertSame(['user_id'], $introspector->relatedKeysFor((new User)->posts()));
        self::assertSame(['user_id'], $introspector->relatedKeysFor((new User)->profile()));
        self::assertSame(['slug'], $introspector->relatedKeysFor($model->organization()));
        self::assertSame(['noteable_id', 'noteable_type'], $introspector->relatedKeysFor((new User)->notes()));
        self::assertSame(['code'], $introspector->relatedKeysFor((new Post)->countries()));
        self::assertSame([], $introspector->relatedKeysFor((new Organization)->posts()));
    }

    /**
     * Test that relatedKeysFor returns null for a MorphTo, whose related model
     * is only known per type, and for an unrecognised relation class.
     *
     * @return void
     */
    public function testRelatedKeysForMorphToAndUnknownRelationsReturnsNull(): void
    {
        $introspector = $this->makeIntrospector();

        self::assertNull($introspector->relatedKeysFor((new Note)->noteable()));
        self::assertNull($introspector->relatedKeysFor(self::createStub(Relation::class)));
    }

    /**
     * Test that the columns are served from definitions another process wrote.
     *
     * @return void
     */
    public function testGetColumnsServesDefinitionsAnotherProcessWrote(): void
    {
        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class, 'users']));

        Cache::memo()->rememberForever($key, fn (): array => [
            ['name' => 'id', 'type_name' => 'integer', 'nullable' => false],
            ['name' => 'name', 'type_name' => 'varchar', 'nullable' => false],
        ]);

        self::assertSame(['id', 'name'], $this->makeIntrospector()->getColumns(new User));
    }

    /**
     * Test that column definitions another process wrote are served warm.
     *
     * @return void
     */
    public function testGetColumnDefinitionsServesDefinitionsAnotherProcessWrote(): void
    {
        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class, 'users']));

        Cache::memo()->rememberForever($key, fn (): array => [
            ['name' => 'id', 'type_name' => 'integer', 'nullable' => false],
            ['name' => 'name', 'type_name' => 'varchar', 'nullable' => true],
        ]);

        self::assertEquals([
            'id'   => new ColumnDefinition('id', 'integer', false),
            'name' => new ColumnDefinition('name', 'varchar', true),
        ], $this->makeIntrospector()->getColumnDefinitions(new User));
    }

    /**
     * Test that an index catalogue another process wrote is served warm.
     *
     * @return void
     */
    public function testGetIndexesServesACatalogueAnotherProcessWrote(): void
    {
        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class, 'users']));

        Cache::memo()->rememberForever($key, fn (): array => [['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        self::assertEquals([new IndexDefinition('users_name_index', ['name'], 'btree')], $this->makeIntrospector()->getIndexes(new User));
    }

    /**
     * Test that column definitions are restored from a class-free store.
     *
     * @return void
     */
    public function testColumnDefinitionsReadBackFromAStoreRefusingEveryClass(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([
                ['name' => 'id', 'type_name' => 'integer', 'nullable' => false],
                ['name' => 'bio', 'type_name' => 'text', 'nullable' => true],
            ]);

        $model = $this->modelReadingFrom($builder);
        $first = $this->makeIntrospector()->getColumnDefinitions($model);

        $this->startAnotherProcess();

        self::assertEquals($first, $this->makeIntrospector()->getColumnDefinitions($model));
        self::assertSame(['id', 'bio'], array_keys($first));
    }

    /**
     * Test that an index catalogue is restored from a class-free store.
     *
     * @return void
     */
    public function testIndexesReadBackFromAStoreRefusingEveryClass(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willReturn([
                ['name' => 'widgets_status_name_index', 'columns' => ['status', 'name'], 'type' => 'btree'],
                ['name' => 'widgets_body_fulltext', 'columns' => ['body'], 'type' => null],
            ]);

        $model = $this->modelReadingFrom($builder);
        $first = $this->makeIntrospector()->getIndexes($model);

        $this->startAnotherProcess();

        self::assertEquals([
            new IndexDefinition('widgets_status_name_index', ['status', 'name'], 'btree'),
            new IndexDefinition('widgets_body_fulltext', ['body'], null),
        ], $first);
        self::assertEquals($first, $this->makeIntrospector()->getIndexes($model));
    }

    /**
     * Provide stored column definitions this version cannot read.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function unreadableColumnDefinitionsProvider(): iterable
    {
        yield 'objects written by an earlier version' => [['id' => self::incompleteObject(new ColumnDefinition('id', 'integer', false))]];
        yield 'a value that is not a list' => ['stale'];
        yield 'a row missing its type' => [[['name' => 'id', 'nullable' => false]]];
        yield 'a readable row beside an unreadable one' => [[['name' => 'id', 'type_name' => 'integer', 'nullable' => false], ['name' => 'name']]];
    }

    /**
     * Test that unreadable stored column definitions are re-read and replaced.
     *
     * @param  mixed  $stale
     * @return void
     */
    #[DataProvider('unreadableColumnDefinitionsProvider')]
    public function testUnreadableStoredColumnDefinitionsAreRecomputedAndReplaced(mixed $stale): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([['name' => 'id', 'type_name' => 'integer', 'nullable' => false]]);

        $model = $this->modelReadingFrom($builder);
        $key   = $this->metadataStorageKey($this->columnDefinitionsKey(SchemaIdentity::of($model->getConnection()), $model));

        $this->storeWithoutEvents($key, $stale);

        self::assertEquals(['id' => new ColumnDefinition('id', 'integer', false)], $this->makeIntrospector()->getColumnDefinitions($model));

        $this->startAnotherProcess();

        self::assertSame([['name' => 'id', 'type_name' => 'integer', 'nullable' => false]], Cache::memo()->get($key));
    }

    /**
     * Provide stored index catalogues this version cannot read.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function unreadableIndexesProvider(): iterable
    {
        yield 'objects written by an earlier version' => [[self::incompleteObject(new IndexDefinition('widgets_name_index', ['name'], 'btree'))]];
        yield 'a value that is not a list' => ['stale'];
        yield 'an entry missing its columns' => [[['name' => 'widgets_name_index', 'type' => 'btree']]];
        yield 'a readable entry beside an unreadable one' => [[['name' => 'widgets_name_index', 'columns' => ['name'], 'type' => 'btree'], ['name' => 'widgets_title_index']]];
    }

    /**
     * Test that an unreadable stored index catalogue is re-read and replaced.
     *
     * @param  mixed  $stale
     * @return void
     */
    #[DataProvider('unreadableIndexesProvider')]
    public function testUnreadableStoredIndexesAreRecomputedAndReplaced(mixed $stale): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getIndexes')
            ->willReturn([['name' => 'widgets_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $model = $this->modelReadingFrom($builder);
        $key   = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey([SchemaIdentity::of($model->getConnection()), $model::class, $model->getTable()]));

        $this->storeWithoutEvents($key, $stale);

        self::assertEquals([new IndexDefinition('widgets_name_index', ['name'], 'btree')], $this->makeIntrospector()->getIndexes($model));

        $this->startAnotherProcess();

        self::assertSame([['name' => 'widgets_name_index', 'columns' => ['name'], 'type' => 'btree']], Cache::memo()->get($key));
    }

    /**
     * Test that an unreadable column row is skipped, keeping the rest.
     *
     * @return void
     */
    public function testGetColumnDefinitionsPassesOverAnUnreadableRow(): void
    {
        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willReturn([
                ['name' => 'id', 'type_name' => null, 'nullable' => false],
                ['name' => 'title', 'type_name' => 'varchar', 'nullable' => false],
            ]);

        self::assertEquals(['title' => new ColumnDefinition('title', 'varchar', false)], $this->makeIntrospector()->getColumnDefinitions($this->modelReadingFrom($builder)));
    }

    /**
     * Test that a fresh introspector serves a multi-column listing from memo.
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
     * Test that getColumnDefinitions lower-cases a mixed-case type name.
     *
     * @return void
     */
    public function testGetColumnDefinitionsLowerCasesDriverReportedTypeName(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

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
     * Test that parentKeysFor returns the local key for MorphOneOrMany.
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
     * Test that parentKeysFor drops empty key names and reindexes the rest.
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
     * Test that getColumns degrades to an empty listing when the lookup fails.
     *
     * @return void
     */
    public function testGetColumnsDegradesToEmptyWhenConnectionUnavailable(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willThrowException(new \RuntimeException('No database connection'));

        self::assertSame([], ($this->makeIntrospector())->getColumns($this->modelReadingFrom($builder)));
    }

    /**
     * Test that getColumnDefinitions degrades to empty when the lookup fails.
     *
     * @return void
     */
    public function testGetColumnDefinitionsDegradesToEmptyWhenConnectionUnavailable(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willThrowException(new \RuntimeException('No database connection'));

        self::assertSame([], ($this->makeIntrospector())->getColumnDefinitions($this->modelReadingFrom($builder)));
    }

    /**
     * Test that a failed getColumnDefinitions lookup is not persisted.
     *
     * @return void
     */
    public function testGetColumnDefinitionsDoesNotCacheEmptyResultOnFailure(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound, staticMethod.dynamicCall

        $builder = self::createMock(SchemaBuilder::class);

        $builder->expects(self::once())
            ->method('getColumns')
            ->willThrowException(new \RuntimeException('No database connection'));

        $model = $this->modelReadingFrom($builder);

        ($this->makeIntrospector())->getColumnDefinitions($model);

        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of($model->getConnection()), $model::class, $model->getTable()]));

        self::assertNull(Cache::memo()->get($key));
    }

    /**
     * Return the key the model's column definitions are stored under.
     *
     * @param  string  $identity
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    private function columnDefinitionsKey(string $identity, Model $model): string
    {
        return CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([$identity, $model::class, $model->getTable()]);
    }

    /**
     * Return the column names stored under the given definitions key.
     *
     * @param  string  $key
     * @return array<int, mixed>
     */
    private function storedColumnNames(string $key): array
    {
        $stored = Cache::memo()->get($this->metadataStorageKey($key));

        self::assertIsArray($stored);

        return array_column($stored, 'name');
    }

    /**
     * Build a model over the keyless staging table, which has no index.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function keylessModel(): Model
    {
        return new #[Table('import_rows')] class extends Model {};
    }

    /**
     * Return the named index's columns, or null when it is absent.
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
     * Build a model on a second connection with a same-named table.
     *
     * A read ignoring the model's connection then reads the wrong catalogue.
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

        return new #[Table('users')] class extends Model {
            /** @var string|\UnitEnum|null */
            protected $connection = 'secondary';
        };
    }

    /**
     * Create a tenant database file with widgets and other_widgets tables.
     *
     * @param  \Closure(\Illuminate\Database\Schema\Blueprint): void  $definition
     * @return string
     */
    private function tenantDatabase(\Closure $definition): string
    {
        $path = sys_get_temp_dir() . '/api-toolkit-tenant-' . getmypid() . '-' . uniqid('', true) . '.sqlite';

        touch($path);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            DB::purge('tenant');
            @unlink($path);
        });

        $this->switchTenant(['database' => $path]);

        Schema::connection('tenant')->create('widgets', $definition);
        Schema::connection('tenant')->create('other_widgets', $definition);

        return $path;
    }

    /**
     * Repoint the tenant connection, as a tenancy switcher does between jobs.
     *
     * @param  array<string, string>  $config
     * @return void
     */
    private function switchTenant(array $config): void
    {
        Config::set('database.connections.tenant', ['driver' => 'sqlite', 'prefix' => '', ...$config]);

        DB::purge('tenant');
    }

    /**
     * Build a model reading the widgets table through the tenant connection.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function tenantModel(): Model
    {
        return new TenantWidget;
    }

    /**
     * Build a model reading its catalogue through the given schema builder.
     *
     * @param  \Illuminate\Database\Schema\Builder&\PHPUnit\Framework\MockObject\MockObject  $builder
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelReadingFrom(MockObject&SchemaBuilder $builder): Model
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($builder);

        return $this->modelOn($connection);
    }

    /**
     * Build a model reading the widgets table through the given connection.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelOn(Connection $connection): Model
    {
        $model = new #[Table('widgets')] class extends Model {
            /** @var \Illuminate\Database\Connection|null The connection this model reads its schema from */
            public ?Connection $reader = null;

            /**
             * Return the connection the model reads its schema from.
             *
             * @return \Illuminate\Database\Connection
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
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
     * Build an unregistered tenant connection over the given database file.
     *
     * @param  string  $database
     * @return \Illuminate\Database\Connection
     */
    private function standaloneTenantConnection(string $database): Connection
    {
        return new SQLiteConnection(static fn (): \PDO => new \PDO('sqlite:' . $database), $database, '', ['name' => 'tenant', 'database' => $database]);
    }

    /**
     * Drop this process's memoised store so reads hit the shared store.
     *
     * @return void
     */
    private function startAnotherProcess(): void
    {
        assert($this->app !== null);

        $this->app->forgetScopedInstances();
    }

    /**
     * Write a value directly to the default store, as an older toolkit left it.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    private function storeWithoutEvents(string $key, mixed $value): void
    {
        Cache::store()->getStore()->forever($key, $value);
    }

    /**
     * Return the object as a process refusing its class reads it back.
     *
     * @param  object  $object
     * @return object
     */
    private static function incompleteObject(object $object): object
    {
        $incomplete = unserialize(serialize($object), ['allowed_classes' => false]);

        assert(is_object($incomplete));

        return $incomplete;
    }

    /**
     * Resolve a schema introspector from the container.
     *
     * @return \SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector
     */
    private function makeIntrospector(): SchemaIntrospector
    {
        assert($this->app !== null);

        return $this->app->make(SchemaIntrospector::class);
    }
}
