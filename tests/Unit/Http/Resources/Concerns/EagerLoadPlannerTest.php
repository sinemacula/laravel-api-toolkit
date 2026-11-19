<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Min;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Sum;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the EagerLoadPlanner.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EagerLoadPlanner::class)]
final class EagerLoadPlannerTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Clear the schema compiler and planner caches before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();
        EagerLoadPlanner::clearCache();

        // Path-walking tests read plain paths; bounded ones set a limit
        Config::set('api-toolkit.resources.relation_limit', 0);
    }

    /**
     * Clear the schema compiler and planner caches after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();
        EagerLoadPlanner::clearCache();

        parent::tearDown();
    }

    /**
     * Test that fields with no relation key produce an empty eager-load map.
     *
     * @return void
     */
    public function testBuildEagerLoadMapReturnsEmptyForFieldsWithNoRelations(): void
    {
        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['id', 'name', 'email']);

        self::assertSame([], $result);
    }

    /**
     * Test that a simple relation field produces a plain eager-load path.
     *
     * @return void
     */
    public function testBuildEagerLoadMapReturnsPlainPathForSimpleRelation(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(null);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);

        self::assertContains('organization', $result);
    }

    /**
     * Test that a relation with a constraint produces a scoped path with a
     * closure.
     *
     * @return void
     */
    public function testBuildEagerLoadMapReturnsScopedPathForConstrainedRelation(): void
    {

        $constrainedResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'constrained_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'items' => [
                        'relation'   => 'items',
                        'constraint' => fn ($query) => $query->where('active', true),
                    ],
                ];
            }
        };

        $resourceClass = $constrainedResource::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['items']);

        self::assertArrayHasKey('items', $result);
        self::assertInstanceOf(\Closure::class, $result['items']);
    }

    /**
     * Test that child resource relations requested via the API query are
     * recursed into and prefixed.
     *
     * @return void
     */
    public function testBuildEagerLoadMapRecursesIntoChildResourceRelations(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('posts')
            ->andReturn(['id', 'title', 'user', 'tags']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(null);

        ApiQuery::shouldReceive('getFields')
            ->with('tags')
            ->andReturn(null);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']);

        self::assertContains('posts', $result);
        self::assertContains('posts.user', $result);
        self::assertContains('posts.tags', $result);
    }

    /**
     * Test that extra eager-load paths from the definition are included.
     *
     * @return void
     */
    public function testBuildEagerLoadMapIncludesExtraPaths(): void
    {

        $resourceWithExtras = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'extras_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'avatar' => [
                        'extras' => ['media', 'media.thumbnails'],
                    ],
                ];
            }
        };

        $resourceClass = $resourceWithExtras::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['avatar']);

        self::assertContains('media', $result);
        self::assertContains('media.thumbnails', $result);
    }

    /**
     * Test that a path several fields load is planned once.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPlansAPathSharedByTwoFieldsOnce(): void
    {

        $resourceWithDuplicatePaths = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'cycle_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'items' => [
                        'relation' => 'items',
                    ],
                    'items_alias' => [
                        'relation' => 'items',
                    ],
                ];
            }
        };

        $resourceClass = $resourceWithDuplicatePaths::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['items', 'items_alias']);

        self::assertSame(['items'], $result);
    }

    /**
     * Test that buildEagerLoadMap returns an empty array when the requested
     * fields do not exist in the schema.
     *
     * @return void
     */
    public function testBuildEagerLoadMapReturnsEmptyForUnknownFields(): void
    {
        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['nonexistent_field']);

        self::assertSame([], $result);
    }

    /**
     * Test that a relation's explicit child fields plan its nested relations in
     * place of the fields the request selects for the child.
     *
     * @return void
     */
    public function testBuildEagerLoadMapUsesExplicitChildFieldsFromDefinition(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->andReturnUsing(static fn (string $type): ?array => $type === 'posts' ? ['title'] : null);

        $parentResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'explicit_fields_parent';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'posts'        => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'tags']],
                    'latest_posts' => ['relation' => 'posts', 'resource' => PostResource::class],
                ];
            }
        };

        self::assertSame(['posts', 'posts.tags'], EagerLoadPlanner::buildEagerLoadMap($parentResource::class, ['posts']));
        self::assertSame(['posts'], EagerLoadPlanner::buildEagerLoadMap($parentResource::class, ['latest_posts']));
    }

    /**
     * Test that the API query fields for a child resource type are used when no
     * explicit fields are defined on the relation.
     *
     * @return void
     */
    public function testBuildEagerLoadMapUsesApiQueryFieldsForChildResource(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(['id', 'name', 'slug']);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);

        self::assertContains('organization', $result);
    }

    /**
     * Test that repeated calls with the same class and fields are served from
     * the memo, so the child field lookup runs only once.
     *
     * @return void
     */
    public function testBuildEagerLoadMapIsMemoisedAcrossRepeatedCalls(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->once()
            ->with('organizations')
            ->andReturn(['id']);

        $first  = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);
        $second = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);

        self::assertSame($first, $second);
    }

    /**
     * Test that clearing the cache forces a full rebuild on the next call, so
     * the child field lookup runs again.
     *
     * @return void
     */
    public function testClearCacheForcesEagerLoadMapRebuild(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->twice()
            ->with('organizations')
            ->andReturn(['id']);

        EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);
        EagerLoadPlanner::clearCache();
        EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);
    }

    /**
     * Test that a second parse selecting other child fields for the same root
     * fields plans afresh, in either order.
     *
     * @return void
     */
    public function testChildFieldSelectionChangesTheMap(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class, TagResource::class]);

        self::assertSame(['posts', 'posts.tags'], $this->planPostsUnder('title,tags'));
        self::assertSame(['posts'], $this->planPostsUnder('title'));
        self::assertSame(['posts', 'posts.tags'], $this->planPostsUnder('title,tags'));
    }

    /**
     * Test that a snapshot selecting other child fields for the same root
     * fields plans afresh inside using(), and again once it returns.
     *
     * @return void
     */
    public function testUsingAndItsRestorePlanAfresh(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class, TagResource::class]);

        self::assertSame(['posts'], $this->planPostsUnder('title'));

        $inside = ApiQuery::using(
            new ApiQuerySnapshot(['fields' => ['posts' => 'title,tags']]),
            static fn (): array => EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']),
        );

        self::assertSame(['posts', 'posts.tags'], $inside);
        self::assertSame(['posts'], EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']));
    }

    /**
     * Test that the eager-load memo is keyed by resource class and field
     * signature, so a seeded entry is returned only for the exact key.
     *
     * @return void
     */
    public function testBuildEagerLoadMapReturnsMemoisedResultForKey(): void
    {
        $this->setStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache', [UserResource::class . '|organization' => ['sentinel_relation']]);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);

        self::assertSame(['sentinel_relation'], $result);
    }

    /**
     * Test that plain and scoped paths are merged into a single map when both
     * are present.
     *
     * @return void
     */
    public function testBuildEagerLoadMapMergesPlainAndScopedPaths(): void
    {

        $mixedResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'mixed_paths_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'plain' => [
                        'relation' => 'plain',
                    ],
                    'scoped' => [
                        'relation'   => 'scoped',
                        'constraint' => fn ($query) => $query,
                    ],
                ];
            }
        };

        $resourceClass = $mixedResource::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['plain', 'scoped']);

        self::assertContains('plain', $result);
        self::assertArrayHasKey('scoped', $result);
        self::assertInstanceOf(\Closure::class, $result['scoped']);
    }

    /**
     * Test that unknown fields are skipped while later relation fields are
     * still planned.
     *
     * @return void
     */
    public function testBuildEagerLoadMapSkipsUnknownFieldsAndContinues(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(null);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['nonexistent_field', 'organization']);

        self::assertContains('organization', $result);
    }

    /**
     * Test that a repeated field is planned once while later fields are still
     * traversed.
     *
     * @return void
     */
    public function testBuildEagerLoadMapContinuesAfterARepeatedField(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(null);

        ApiQuery::shouldReceive('getFields')
            ->with('posts')
            ->andReturn(null);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization', 'organization', 'posts']);

        self::assertContains('organization', $result);
        self::assertContains('posts', $result);
    }

    /**
     * Test that relations pointing at non-ApiResource classes are not recursed
     * into.
     *
     * @return void
     */
    public function testBuildEagerLoadMapDoesNotRecurseIntoNonResourceClasses(): void
    {

        $nonResourceChild = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'non_resource_child_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'organization' => [
                        'relation' => 'organization',
                        'resource' => \stdClass::class,
                    ],
                ];
            }
        };

        $resourceClass = $nonResourceChild::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['organization']);

        self::assertSame(['organization'], $result);
    }

    /**
     * Test that empty entries in an explicit child field projection are
     * filtered out before recursion.
     *
     * @return void
     */
    public function testBuildEagerLoadMapFiltersEmptyExplicitChildFields(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('posts')
            ->andReturn(null);

        ApiQuery::shouldReceive('getFields')
            ->with('tags')
            ->andReturn(null);

        $explicitFieldsResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'blank_explicit_fields_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'posts' => [
                        'relation' => 'posts',
                        'resource' => PostResource::class,
                        'fields'   => ['', 'tags'],
                    ],
                ];
            }
        };

        $resourceClass = $explicitFieldsResource::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['posts']);

        self::assertContains('posts', $result);
        self::assertContains('posts.tags', $result);
    }

    /**
     * Test that clearing the cache resets the eager-load and aggregate memos.
     *
     * @return void
     */
    public function testClearCacheResetsTheEagerLoadAndAggregateMemos(): void
    {
        $this->setStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache', ['key' => ['x']]);
        $this->setStaticProperty(EagerLoadPlanner::class, 'aggregateCache', [
            'key' => [['relation' => 'x', 'column' => 'y', 'function' => 'sum']],
        ]);

        EagerLoadPlanner::clearCache();

        self::assertSame([], $this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache'));
        self::assertSame([], $this->getStaticProperty(EagerLoadPlanner::class, 'aggregateCache'));
    }

    /**
     * Test that a blank request for a child selects none of its relations, as
     * it renders none.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPlansNoChildRelationUnderABlankRequest(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('blank_fields_child')
            ->andReturn(['']);

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(null);

        $child = new class (null) extends ApiResource {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'blank_fields_child';

            /** @var array<int, string> */
            protected static array $default = ['organization'];

            /**
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'organization' => [
                        'relation' => 'organization',
                        'resource' => OrganizationResource::class,
                    ],
                ];
            }
        };

        $parent = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'blank_fields_parent';

            /** @var string */
            public static string $childClass = '';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'rel' => [
                        'relation' => 'rel',
                        'resource' => self::$childClass,
                    ],
                ];
            }
        };

        $parent::$childClass = $child::class;

        $result = EagerLoadPlanner::buildEagerLoadMap($parent::class, ['rel']);

        self::assertSame(['rel'], $result);
    }

    /**
     * Test that with no request the default aggregates are selected, aliased
     * under their loaded attributes.
     *
     * @return void
     */
    public function testBuildAggregateMapSelectsTheDefaultsWhenNoneAreRequested(): void
    {
        $expected = [
            ['relation' => 'posts as aggregate_posts_count', 'column' => '*', 'function' => 'count'],
            ['relation' => 'posts as aggregate_posts_sum_id', 'column' => 'id', 'function' => 'sum'],
        ];

        self::assertSame($expected, EagerLoadPlanner::buildAggregateMap(UserResource::class));
        self::assertSame($expected, EagerLoadPlanner::buildAggregateMap(UserResource::class, []));
        self::assertSame([], EagerLoadPlanner::buildAggregateMap(OrganizationResource::class));
    }

    /**
     * Test that a non-empty request selects exactly its keys, dropping every
     * default of the type.
     *
     * @return void
     */
    public function testBuildAggregateMapSelectsExactlyTheRequestedKeys(): void
    {
        self::assertSame(
            [['relation' => 'posts as aggregate_posts_avg_id', 'column' => 'id', 'function' => 'avg']],
            EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts_avg_id', 'unknown']),
        );

        self::assertSame(
            [['relation' => 'users as aggregate_users_count', 'column' => '*', 'function' => 'count']],
            EagerLoadPlanner::buildAggregateMap(OrganizationResource::class, ['users_count']),
        );

        self::assertSame([], EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts', 'posts_id']));
    }

    /**
     * Test that two constrained sums over one column are selected separately,
     * each with its own constraint.
     *
     * @return void
     */
    public function testBuildAggregateMapSelectsConstrainedSumsOverOneColumnSeparately(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'constrained_sums_test';

            /** @var array<string, \Closure(mixed): mixed> The constraints keyed by aggregate key */
            public static array $constraints = [];

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                self::$constraints = [
                    'revenue'      => static fn ($query) => $query,
                    'paid_revenue' => static fn ($query) => $query->where('paid', true),
                ];

                return Field::set(
                    Sum::of('orders', 'total', 'revenue')->constrain(self::$constraints['revenue']),
                    Sum::of('orders', 'total', 'paid_revenue')->constrain(self::$constraints['paid_revenue']),
                );
            }
        };

        $paid = EagerLoadPlanner::buildAggregateMap($resource::class, ['paid_revenue']);
        $both = EagerLoadPlanner::buildAggregateMap($resource::class, ['revenue', 'paid_revenue']);

        self::assertSame([['relation' => ['orders as aggregate_paid_revenue' => $resource::$constraints['paid_revenue']], 'column' => 'total', 'function' => 'sum']], $paid);
        self::assertSame(['orders as aggregate_revenue' => $resource::$constraints['revenue']], $both[0]['relation']);
        self::assertSame(['orders as aggregate_paid_revenue' => $resource::$constraints['paid_revenue']], $both[1]['relation']);
    }

    /**
     * Test that every function is planned with its own SQL function and column.
     *
     * @return void
     */
    public function testBuildAggregateMapPlansEveryFunction(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'every_function_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return Field::set(
                    Exists::of('posts')->default(),
                    Min::of('posts', 'created_at')->default(),
                    Max::of('posts', 'created_at')->default(),
                );
            }
        };

        self::assertSame([
            ['relation' => 'posts as aggregate_posts_exists', 'column' => '*', 'function' => 'exists'],
            ['relation' => 'posts as aggregate_posts_min_created_at', 'column' => 'created_at', 'function' => 'min'],
            ['relation' => 'posts as aggregate_posts_max_created_at', 'column' => 'created_at', 'function' => 'max'],
        ], EagerLoadPlanner::buildAggregateMap($resource::class));
    }

    /**
     * Test that the aggregate memo is keyed by resource class and requested
     * keys, an empty request sharing the defaults' entry.
     *
     * @return void
     */
    public function testBuildAggregateMapReturnsTheMemoisedResultForTheKey(): void
    {
        $this->setStaticProperty(EagerLoadPlanner::class, 'aggregateCache', [
            UserResource::class . '|*'                                             => [['relation' => 'defaults', 'column' => '*', 'function' => 'count']],
            UserResource::class . '|' . serialize(['posts_count', 'posts_sum_id']) => [['relation' => 'requested', 'column' => '*', 'function' => 'count']],
        ]);

        self::assertSame('defaults', EagerLoadPlanner::buildAggregateMap(UserResource::class)[0]['relation']);
        self::assertSame('defaults', EagerLoadPlanner::buildAggregateMap(UserResource::class, [])[0]['relation']);
        self::assertSame('requested', EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts_count', 'posts_sum_id'])[0]['relation']);
        self::assertSame('posts as aggregate_posts_count', EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts_count'])[0]['relation']);
    }

    /**
     * Test that no requested key list can reach the memo entry of the defaults
     * or of another list.
     *
     * @return void
     */
    public function testBuildAggregateMapKeepsEveryRequestedListApart(): void
    {
        $defaults = EagerLoadPlanner::buildAggregateMap(UserResource::class);
        $split    = EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts_count', 'posts_sum_id']);

        self::assertCount(2, $defaults);
        self::assertSame([], EagerLoadPlanner::buildAggregateMap(UserResource::class, ['*']));
        self::assertSame([], EagerLoadPlanner::buildAggregateMap(UserResource::class, ["posts_count\0posts_sum_id"]));
        self::assertCount(2, $split);
        self::assertSame($defaults, EagerLoadPlanner::buildAggregateMap(UserResource::class));
    }

    /**
     * Test that an aggregate is recognised as loaded by matching the aliased
     * attribute against the model, so a pre-loaded aggregate is not re-queried
     * per row.
     *
     * @return void
     */
    public function testIsAggregateAttributeLoadedMatchesTheAliasedAttribute(): void
    {
        $user = new User;
        $user->setAttribute('posts_sum_id', 10);
        $user->setAttribute('plain', 1);

        // The alias after ' as ' is matched against the loaded attributes.
        self::assertTrue(EagerLoadPlanner::isAggregateAttributeLoaded($user, 'posts as posts_sum_id'));
        self::assertFalse(EagerLoadPlanner::isAggregateAttributeLoaded($user, 'comments as comments_sum_id'));

        // The constrained (array-keyed) form reads the key.
        self::assertTrue(EagerLoadPlanner::isAggregateAttributeLoaded($user, ['posts as posts_sum_id' => static fn (): null => null]));

        // A specification without an alias is matched verbatim.
        self::assertTrue(EagerLoadPlanner::isAggregateAttributeLoaded($user, 'plain'));
        self::assertFalse(EagerLoadPlanner::isAggregateAttributeLoaded($user, 'missing'));

        // A non-string, non-array specification is never loaded.
        self::assertFalse(EagerLoadPlanner::isAggregateAttributeLoaded($user, 123));

        // An object without getAttributes() is never loaded.
        self::assertFalse(EagerLoadPlanner::isAggregateAttributeLoaded(new \stdClass, 'plain'));
    }

    /**
     * Test that a blank extra path is skipped while a real extra path is still
     * collected.
     *
     * @return void
     */
    public function testBuildEagerLoadMapSkipsBlankExtraPaths(): void
    {

        $blankExtraResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'blank_extra_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'avatar' => [
                        'extras' => ['', 'media'],
                    ],
                ];
            }
        };

        $result = EagerLoadPlanner::buildEagerLoadMap($blankExtraResource::class, ['avatar']);

        self::assertContains('media', $result);
        self::assertNotContains('', $result);
    }

    /**
     * Test that recursion halts when a child field projection resolves to an
     * empty set, so the parent relation is loaded without any child paths.
     *
     * @return void
     */
    public function testBuildEagerLoadMapStopsRecursionWhenChildFieldsResolveEmpty(): void
    {

        $emptyChildFieldsResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'empty_child_fields_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'posts' => [
                        'relation' => 'posts',
                        'resource' => PostResource::class,
                        'fields'   => [''],
                    ],
                ];
            }
        };

        $result = EagerLoadPlanner::buildEagerLoadMap($emptyChildFieldsResource::class, ['posts']);

        self::assertContains('posts', $result);
        self::assertNotContains('posts.tags', $result);
    }

    /**
     * Test that a child's planned fields are its selection followed by its
     * fixed fields, and that a field rendering no resource plans none.
     *
     * @return void
     */
    public function testResolveChildFieldsAppendsTheChildsFixedFields(): void
    {
        $child  = new CompiledFieldDefinition(null, null, 'posts', PostResource::class, ['tags'], null, [], [], [], []);
        $scalar = new CompiledFieldDefinition(null, null, 'posts', null, ['tags'], null, [], [], [], []);

        self::assertSame(['tags', 'id', '_type'], $this->invokeStaticMethod(EagerLoadPlanner::class, 'resolveChildFields', $child));
        self::assertSame([], $this->invokeStaticMethod(EagerLoadPlanner::class, 'resolveChildFields', $scalar));
    }

    /**
     * Test that a pair of resources naming each other stops instead of
     * descending without bound.
     *
     * Paths lengthen on every descent, so only the resources already on the
     * path end a cycle.
     *
     * @return void
     */
    public function testBuildEagerLoadMapStopsWhenAResourcePairNamesEachOther(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->andReturnUsing(fn (string $type) => match ($type) {
                'users' => ['posts'],
                'posts' => ['user'],
                default => null,
            });

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']);

        self::assertContains('posts', $result);
        self::assertContains('posts.user', $result);

        // Descending again would yield 'posts.user.posts' and never terminate
        foreach (array_merge(array_values($result), array_keys($result)) as $path) {
            if (!is_string($path)) {
                continue;
            }

            self::assertLessThanOrEqual(
                2,
                substr_count($path, '.'),
                sprintf('The walk must not descend past the cycle: %s', $path),
            );
        }
    }

    /**
     * Test that fields over one relation each plan the nested relations their
     * own projection selects.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPlansEachAliasProjectionOverOneRelation(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'aliased_projection_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'posts'        => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'tags']],
                    'post_authors' => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'user']],
                ];
            }
        };

        ApiQuery::shouldReceive('getFields')->andReturn(null);

        self::assertSame(['posts', 'posts.tags', 'posts.user'], EagerLoadPlanner::buildEagerLoadMap($resource::class, ['posts', 'post_authors']));
    }

    /**
     * Test that two relations rendered through one child resource each plan
     * that child's nested relation under their own path.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPlansTheSameChildUnderEveryPath(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'shared_child_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'billing_address'  => ['relation' => 'billing_address', 'resource' => PostResource::class, 'fields' => ['user']],
                    'shipping_address' => ['relation' => 'shipping_address', 'resource' => PostResource::class, 'fields' => ['user']],
                ];
            }
        };

        ApiQuery::shouldReceive('getFields')->andReturn(null);

        self::assertSame(
            ['billing_address', 'billing_address.user', 'shipping_address', 'shipping_address.user'],
            EagerLoadPlanner::buildEagerLoadMap($resource::class, ['billing_address', 'shipping_address']),
        );
    }

    /**
     * Test that a path bounded by one field and loaded plainly by another is
     * planned once, as the bounded entry.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPlansABoundedPathOnlyAsItsScopedEntry(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'scoped_and_plain_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'post_titles' => ['relation' => 'posts', 'accessor' => 'title'],
                    'posts'       => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id'], 'limit' => 5],
                ];
            }
        };

        $map = EagerLoadPlanner::buildEagerLoadMap($resource::class, ['post_titles', 'posts']);

        self::assertSame(['posts'], array_keys($map));
        self::assertInstanceOf(\Closure::class, $map['posts']);
    }

    /**
     * Test that a relation with no constraint but a limit plans a closure.
     *
     * @return void
     */
    public function testBuildEagerLoadMapScopesALimitedRelationWithoutAConstraint(): void
    {
        $map = EagerLoadPlanner::buildEagerLoadMap(self::boundedResource(['limit' => 5]), ['posts']);

        self::assertNotContains('posts', $map);
        self::assertInstanceOf(\Closure::class, $map['posts'] ?? null);
    }

    /**
     * Test that the default limit applies to a relation declaring none.
     *
     * @return void
     */
    public function testBuildEagerLoadMapBoundsAnUndeclaredRelationByTheDefault(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 4);

        $relation = $this->applyPlanned(self::boundedResource(), $this->eagerRelation(new User, 'posts'));

        self::assertSame(['value' => 5, 'column' => 'posts.user_id'], $relation->getQuery()->getQuery()->groupLimit);
    }

    /**
     * Test that the constraint receives the relation itself.
     *
     * @return void
     */
    public function testConstraintReceivesTheRelation(): void
    {
        $received = null;
        $relation = $this->eagerRelation(new User, 'posts');

        $this->applyPlanned(self::boundedResource(['constraint' => static function ($query) use (&$received): void {
            $received = $query;
        }]), $relation);

        self::assertInstanceOf(HasMany::class, $received);
        self::assertSame($relation, $received);
    }

    /**
     * Test that a constraint bounding the relation is refused, naming the
     * resource and field it was declared on.
     *
     * @return void
     */
    public function testConstraintThatBoundsTheRelationIsRefusedNamingItsField(): void
    {
        $resourceClass = self::boundedResource(['constraint' => static function ($query): void {
            $query->limit(5);
        }]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The constraint on ' . $resourceClass . '::posts may not limit or offset the relation; declare limit() on the relation instead');

        $this->applyPlanned($resourceClass, $this->eagerRelation(new User, 'posts'));
    }

    /**
     * Test that a declared limit bounds the relation per parent with a probe
     * row, ordered last by the related key.
     *
     * @return void
     */
    public function testDeclaredLimitBoundsPerParentWithAProbeRowAndKeyOrder(): void
    {
        $relation = $this->applyPlanned(self::boundedResource([
            'limit'      => 5,
            'constraint' => static function ($query): void {
                $query->latest();
            },
        ]), $this->eagerRelation(new User, 'posts'));

        self::assertSame(['value' => 6, 'column' => 'posts.user_id'], $relation->getQuery()->getQuery()->groupLimit);
        self::assertSame([
            ['column' => 'created_at', 'direction' => 'desc'],
            ['column' => 'posts.id', 'direction' => 'asc'],
        ], $relation->getQuery()->toBase()->orders);
    }

    /**
     * Test that with the limit disabled a constrained closure adds no order and
     * no bound.
     *
     * @return void
     */
    public function testDisabledLimitAddsNoOrderAndNoBound(): void
    {
        $relation = $this->applyPlanned(self::boundedResource(['constraint' => static function ($query): void {
            $query->where('published', true);
        }]), $this->eagerRelation(new User, 'posts'));

        self::assertNull($relation->getQuery()->getQuery()->groupLimit);
        self::assertNull($relation->getQuery()->getQuery()->limit);
        self::assertSame([], $relation->getQuery()->toBase()->orders ?? []);
    }

    /**
     * Test that a child selecting its aggregates loads the requested ones in
     * the closure owning its path.
     *
     * @return void
     */
    public function testChildAggregatesLoadInTheClosureOwningThePath(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        $sql = $this->applyPlanned(self::boundedResource(['fields' => ['id', 'aggregates']]), $this->eagerRelation(new User, 'posts'))->toSql();

        self::assertStringStartsWith('select "posts".*, (select count(*) from "tags"', $sql);
        self::assertStringContainsString('as "aggregate_tags_count"', $sql);
    }

    /**
     * Test that a decorator handed to the closure owning a path rewrites the
     * aggregates it loads.
     *
     * @return void
     */
    public function testAggregateDecoratorRewritesTheAggregatesTheClosureLoads(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        $decorate = static fn (array $entries): array => array_map(static fn (array $entry): array => ['relation' => 'tags as decorated_count'] + $entry, $entries);

        $sql = $this->applyPlanned(self::boundedResource(['fields' => ['id', 'aggregates']]), $this->eagerRelation(new User, 'posts'), $decorate)->toSql();

        self::assertStringContainsString('as "decorated_count"', $sql);
        self::assertStringNotContainsString('aggregate_tags_count', $sql);
    }

    /**
     * Test that a child requesting no aggregate loads its defaults.
     *
     * @return void
     */
    public function testChildAggregatesFallBackToTheChildsDefaults(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $resource = self::boundedResource(['relation' => 'user', 'resource' => UserResource::class, 'fields' => ['name', 'aggregates']]);
        $sql      = $this->applyPlanned($resource, $this->eagerRelation(new Post, 'user'))->toSql();

        self::assertStringContainsString('"aggregate_posts_count"', $sql);
        self::assertStringContainsString('"aggregate_posts_sum_id"', $sql);
        self::assertStringNotContainsString('"aggregate_posts_avg_id"', $sql);
    }

    /**
     * Test that fields over one bounded relation load the aggregates any of
     * them selects, each once.
     *
     * @return void
     */
    public function testFieldsOverOneRelationKeepEachOthersAggregates(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'aliased_aggregates_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'posts'         => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'aggregates'], 'limit' => 5],
                    'metered_posts' => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'aggregates'], 'limit' => 5],
                    'titled_posts'  => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['title'], 'limit' => 5],
                ];
            }
        };

        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        $closure = EagerLoadPlanner::buildEagerLoadMap($resource::class, ['posts', 'metered_posts', 'titled_posts'])['posts'] ?? null;

        self::assertInstanceOf(\Closure::class, $closure);

        $relation = $this->eagerRelation(new User, 'posts');

        $closure($relation);

        self::assertSame(1, substr_count($relation->toSql(), '"aggregate_tags_count"'));
    }

    /**
     * Test that a child leaving its aggregates unselected keeps its path plain,
     * whatever its type requests.
     *
     * @return void
     */
    public function testChildNotSelectingItsAggregatesKeepsItsPathPlain(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        self::assertSame(['posts'], EagerLoadPlanner::buildEagerLoadMap(self::boundedResource(), ['posts']));
    }

    /**
     * Test that a child with no default and no requested field plans none of
     * its relations.
     *
     * @return void
     */
    public function testChildWithoutDefaultsPlansNoRelation(): void
    {
        ApiQuery::shouldReceive('getFields')->andReturn(null);

        self::assertSame(['organization'], EagerLoadPlanner::buildEagerLoadMap(self::boundedResource([
            'relation' => 'organization',
            'resource' => self::defaultlessChild(),
            'fields'   => null,
        ]), ['posts']));
    }

    /**
     * Test that a path the ancestor cycle stops descending still loads the
     * child's aggregates.
     *
     * @return void
     */
    public function testPathTheCycleCutsStillLoadsTheChildsAggregates(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);
        ApiQuery::shouldReceive('getFields')
            ->andReturnUsing(static fn (string $type): ?array => match ($type) {
                'users' => ['name', 'aggregates'],
                'posts' => ['user'],
                default => null,
            });

        $map = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']);

        self::assertSame([0, 'posts.user'], array_keys($map));
        self::assertSame('posts', $map[0]);
        self::assertInstanceOf(\Closure::class, $map['posts.user']);
    }

    /**
     * Test that a column selection on a path loading aggregates is folded into
     * its closure ahead of them.
     *
     * @return void
     */
    public function testColumnSelectionIsFoldedAheadOfTheAggregates(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        $resource = self::boundedResource(['fields' => ['id', 'aggregates'], 'extras' => ['posts:id,user_id']]);

        self::assertSame(['posts'], array_keys(EagerLoadPlanner::buildEagerLoadMap($resource, ['posts'])));

        $sql = $this->applyPlanned($resource, $this->eagerRelation(new User, 'posts'))->toSql();

        self::assertStringStartsWith('select "id", "user_id", (select count(*) from "tags"', $sql);
    }

    /**
     * Test that a column selection on a path loading no aggregate stays a plain
     * entry.
     *
     * @return void
     */
    public function testColumnSelectionWithoutAggregatesStaysPlain(): void
    {
        self::assertSame(['posts:id,user_id', 'posts'], EagerLoadPlanner::buildEagerLoadMap(self::boundedResource(['extras' => ['posts:id,user_id']]), ['posts']));
    }

    /**
     * Test that a folded column selection is qualified on a relation that joins
     * another table.
     *
     * @return void
     */
    public function testFoldedColumnSelectionIsQualifiedOnAJoiningRelation(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        $resource = self::boundedResource(['fields' => ['id', 'aggregates'], 'extras' => ['posts:id,title']]);
        $sql      = $this->applyPlanned($resource, $this->eagerRelation(new Organization, 'posts'))->toSql();

        self::assertStringStartsWith('select "posts"."id", "posts"."title", (select count(*) from "tags"', $sql);
    }

    /**
     * Test that taking a path's column selections merges every one of them and
     * leaves each other entry, a nested path under it included.
     *
     * @return void
     */
    public function testTakeColumnSelectionMergesThePathsSelectionsOnly(): void
    {
        /** @var array<int, string> $plain */
        $plain   = ['media', 'posts.tags', 'posts:id,user_id', 'postsArchive:id', 'posts:user_id,title'];
        $columns = (new \ReflectionMethod(EagerLoadPlanner::class, 'takeColumnSelection'))->invokeArgs(null, [&$plain, 'posts']);

        self::assertSame(['id', 'user_id', 'title'], $columns);
        self::assertSame(['media', 'posts.tags', 'postsArchive:id'], array_values($plain));
    }

    /**
     * Test that a folded selection is qualified only where the relation joins
     * another table.
     *
     * @return void
     */
    public function testQualifyColumnsQualifiesOnlyAJoiningRelation(): void
    {
        self::assertSame(['tags.id'], $this->invokeStaticMethod(EagerLoadPlanner::class, 'qualifyColumns', (new Post)->tags(), ['id']));
        self::assertSame(['id'], $this->invokeStaticMethod(EagerLoadPlanner::class, 'qualifyColumns', (new User)->profile(), ['id']));
    }

    /**
     * Test that a MorphTo path loads no aggregate, since each type loads
     * through a fresh query.
     *
     * @return void
     */
    public function testMorphToPathLoadsNoAggregate(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $resource = self::boundedResource(['relation' => 'noteable', 'resource' => UserResource::class, 'fields' => ['aggregates']]);
        $relation = $this->applyPlanned($resource, $this->eagerRelation(new Note, 'noteable'));

        self::assertNull($relation->getQuery()->getQuery()->columns);
    }

    /**
     * Test that a constrained and bounded path loading aggregates applies the
     * constraint, the aggregates and the bound.
     *
     * @return void
     */
    public function testConstrainedBoundedPathAppliesEveryStep(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('posts')->andReturn(['tags_count']);

        $relation = $this->applyPlanned(self::boundedResource([
            'fields'     => ['id', 'aggregates'],
            'limit'      => 5,
            'constraint' => static function ($query): void {
                $query->where('published', true);
            },
        ]), $this->eagerRelation(new User, 'posts'));

        $base = $relation->getQuery()->getQuery();

        self::assertSame(['value' => 6, 'column' => 'posts.user_id'], $base->groupLimit);
        self::assertSame([true], $base->getBindings());
        self::assertStringContainsString('"aggregate_tags_count"', $relation->toSql());
    }

    /**
     * Test that a path declaring pivot columns selects them in the closure that
     * owns it, while a path without them stays plain.
     *
     * @return void
     */
    public function testPivotColumnsAreSelectedInTheClosureOwningThePath(): void
    {
        $tags = ['relation' => 'tags', 'resource' => TagResource::class];

        self::assertSame(['tags'], EagerLoadPlanner::buildEagerLoadMap(self::boundedResource($tags), ['posts']));

        SchemaCompiler::clearCache();
        EagerLoadPlanner::clearCache();

        $relation = $this->applyPlanned(self::boundedResource([...$tags, 'pivot' => ['role', 'tagged_at']]), $this->eagerRelation(new Post, 'tags'));

        self::assertInstanceOf(BelongsToMany::class, $relation);
        self::assertSame(['role', 'tagged_at'], $relation->getPivotColumns());
    }

    /**
     * Test that the pivot columns compose with the field's constraint.
     *
     * @return void
     */
    public function testPivotColumnsComposeWithTheConstraint(): void
    {
        $relation = $this->applyPlanned(self::boundedResource([
            'relation'   => 'tags',
            'resource'   => TagResource::class,
            'pivot'      => ['role'],
            'constraint' => static function ($query): void {
                assert($query instanceof BelongsToMany);
                $query->wherePivot('role', 'primary');
            },
        ]), $this->eagerRelation(new Post, 'tags'));

        self::assertInstanceOf(BelongsToMany::class, $relation);
        self::assertSame(['role'], $relation->getPivotColumns());
        self::assertContains('post_tag.role', array_column($relation->getQuery()->getQuery()->wheres, 'column'));
    }

    /**
     * Test that fields over one many-to-many path select the union of their
     * pivot columns.
     *
     * @return void
     */
    public function testFieldsOverOnePathUnionTheirPivotColumns(): void
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'aliased_pivot_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'tags'        => ['relation' => 'tags', 'resource' => TagResource::class, 'pivot' => ['role']],
                    'plain_tags'  => ['relation' => 'tags', 'resource' => TagResource::class],
                    'tagged_tags' => ['relation' => 'tags', 'resource' => TagResource::class, 'pivot' => ['tagged_at', 'role']],
                ];
            }
        };

        $map      = EagerLoadPlanner::buildEagerLoadMap($resource::class, ['tags', 'plain_tags', 'tagged_tags']);
        $relation = $this->eagerRelation(new Post, 'tags');

        self::assertSame(['tags'], array_keys($map));
        self::assertInstanceOf(\Closure::class, $map['tags']);
        self::assertInstanceOf(BelongsToMany::class, $relation);

        $map['tags']($relation);

        self::assertSame(['role', 'tagged_at'], $relation->getPivotColumns());
    }

    /**
     * Test that pivot columns on a relation that is not many-to-many are
     * refused, naming the field.
     *
     * @return void
     */
    public function testPivotColumnsOnARelationThatIsNotManyToManyAreRefused(): void
    {
        $resourceClass = self::boundedResource(['pivot' => ['role']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The ' . $resourceClass . '::posts relation declares pivot columns but is not a many-to-many relation');

        $this->applyPlanned($resourceClass, $this->eagerRelation(new User, 'posts'));
    }

    /**
     * Test that the projection plan unions the fields every alias over a path
     * renders, each child's fixed fields included.
     *
     * @return void
     */
    public function testProjectionPlanUnionsTheFieldsOfEveryAliasOverAPath(): void
    {
        $resource = self::projectingResource([
            'post_authors' => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'user']],
        ]);

        ApiQuery::shouldReceive('getFields')->andReturn(null);

        $plan = EagerLoadPlanner::buildProjectionPlan($resource, ['posts', 'post_authors']);

        self::assertSame(['posts', 'posts.user'], array_keys($plan));
        self::assertSame(PostResource::class, $plan['posts']->resource);
        self::assertSame(['id', 'title', 'user', '_type'], $plan['posts']->fields);
        self::assertFalse($plan['posts']->opaque);
        self::assertSame(UserResource::class, $plan['posts.user']->resource);
        self::assertFalse($plan['posts.user']->opaque);
    }

    /**
     * Test that a child's fields are listed once each, its fixed fields after
     * the fields it selects.
     *
     * @return void
     */
    public function testProjectionPlanListsEachChildFieldOnce(): void
    {
        ApiQuery::shouldReceive('getFields')->andReturn(null);

        self::assertSame(['id', 'title', 'user', '_type'], EagerLoadPlanner::buildProjectionPlan(self::projectingResource([]), ['posts'])['posts']->fields);
    }

    /**
     * Provide siblings that read the posts other than through PostResource.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function relatedModelReaders(): iterable
    {
        yield 'string accessor' => [['relation' => 'posts', 'resource' => PostResource::class, 'accessor' => 'title']];
        yield 'callable accessor' => [['relation' => 'posts', 'resource' => PostResource::class, 'accessor' => static fn (): null => null]];
        yield 'raw relation' => [['relation' => 'posts']];
        yield 'extras consumer' => [['accessor' => static fn (): null => null, 'needs' => ['id'], 'extras' => ['comments', 'posts']]];
        yield 'nested extras consumer' => [['accessor' => static fn (): null => null, 'needs' => ['id'], 'extras' => ['posts.user:id,name']]];
    }

    /**
     * Test that a sibling reading the related models other than through the
     * child resource marks the path and its subtree opaque, whichever field is
     * planned first.
     *
     * @param  array<string, mixed>  $sibling
     * @return void
     */
    #[DataProvider('relatedModelReaders')]
    public function testAnotherReaderOfTheRelatedModelsMarksThePathAndItsSubtreeOpaque(array $sibling): void
    {
        $resource = self::projectingResource(['sibling' => $sibling]);

        ApiQuery::shouldReceive('getFields')->andReturn(null);

        foreach ([['posts', 'sibling'], ['sibling', 'posts']] as $fields) {

            $plan = EagerLoadPlanner::buildProjectionPlan($resource, $fields);

            self::assertSame(PostResource::class, $plan['posts']->resource);
            self::assertTrue($plan['posts']->opaque);
            self::assertTrue($plan['posts.user']->opaque);
        }
    }

    /**
     * Test that the projection plan is memoised per resource and field list
     * beside the eager-load map, and cleared with it.
     *
     * @return void
     */
    public function testProjectionPlanIsMemoisedAndClearedWithTheEagerLoadMap(): void
    {
        ApiQuery::shouldReceive('getFields')->andReturn(null);

        $first = EagerLoadPlanner::buildProjectionPlan(UserResource::class, ['posts']);

        self::assertSame($first['posts'], EagerLoadPlanner::buildProjectionPlan(UserResource::class, ['posts'])['posts']);
        self::assertSame(['posts'], EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']));
        self::assertSame([], EagerLoadPlanner::buildProjectionPlan(UserResource::class, ['name']));
        self::assertSame([], EagerLoadPlanner::buildProjectionPlan(PostResource::class, ['posts']));

        EagerLoadPlanner::clearCache();

        self::assertNotSame($first['posts'], EagerLoadPlanner::buildProjectionPlan(UserResource::class, ['posts'])['posts']);
    }

    /**
     * Build a resource whose single posts field carries the given definition.
     *
     * @param  array<string, mixed>  $definition
     * @return string
     */
    private static function boundedResource(array $definition = []): string
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'bounded_planner_test';

            /** @var array<string, mixed> */
            public static array $definition = [];

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return ['posts' => self::$definition];
            }
        };

        $resource::$definition = ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id'], ...$definition];

        return $resource::class;
    }

    /**
     * Build a resource rendering posts through the post resource beside the
     * given fields.
     *
     * @param  array<string, array<string, mixed>>  $siblings
     * @return string
     */
    private static function projectingResource(array $siblings): string
    {
        $resource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'projecting_planner_test';

            /** @var array<string, array<string, mixed>> */
            public static array $siblings = [];

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'name'  => [],
                    'posts' => ['relation' => 'posts', 'resource' => PostResource::class, 'fields' => ['id', 'title', 'user']],
                    ...self::$siblings,
                ];
            }
        };

        $resource::$siblings = $siblings;

        return $resource::class;
    }

    /**
     * Build a child resource declaring a relation but no default field.
     *
     * @return string
     */
    private static function defaultlessChild(): string
    {
        $child = new class (null) extends ApiResource {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'defaultless_planner_child';

            /**
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return ['organization' => ['relation' => 'organization', 'resource' => OrganizationResource::class]];
            }
        };

        return $child::class;
    }

    /**
     * Run the closure planned for the resource's single field on the relation.
     *
     * @param  string  $resourceClass
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  (\Closure(array<mixed>): array<mixed>)|null  $decorateAggregates
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
     */
    private function applyPlanned(string $resourceClass, EloquentRelation $relation, ?\Closure $decorateAggregates = null): EloquentRelation
    {
        $closure = current(EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['posts']));

        self::assertInstanceOf(\Closure::class, $closure);

        $closure($relation, $decorateAggregates);

        return $relation;
    }

    /**
     * Build the relation as an eager load does, from an unsaved parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $name
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
     */
    private function eagerRelation(Model $model, string $name): EloquentRelation
    {
        $relation = EloquentRelation::noConstraints(static fn (): mixed => $model->{$name}()); // @phpstan-ignore method.dynamicName

        assert($relation instanceof EloquentRelation);

        return $relation;
    }

    /**
     * Parse a request selecting the given post fields and plan the users' posts
     * under it.
     *
     * @param  string  $postFields
     * @return array<int|string, mixed>
     */
    private function planPostsUnder(string $postFields): array
    {
        ApiQuery::parse(Request::create('/users', 'GET', ['fields' => ['posts' => $postFields]]));

        return EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['posts']);
    }
}
