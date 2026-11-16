<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Min;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Sum;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
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
     * Test that visited resource/path pairs are not traversed again.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPreventsCyclicTraversal(): void
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

        $plainPaths = array_values(array_filter($result, 'is_string'));
        $itemsCount = count(array_filter($plainPaths, static fn (string $path): bool => $path === 'items'));

        self::assertSame(1, $itemsCount);
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
     * Test that explicit child fields on a relation definition are used for
     * recursion instead of defaults.
     *
     * @return void
     */
    public function testBuildEagerLoadMapUsesExplicitChildFieldsFromDefinition(): void
    {

        $parentResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'explicit_fields_parent';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'organization' => [
                        'relation' => 'organization',
                        'resource' => OrganizationResource::class,
                        'fields'   => ['id', 'name'],
                    ],
                ];
            }
        };

        $resourceClass = $parentResource::class;
        $result        = EagerLoadPlanner::buildEagerLoadMap($resourceClass, ['organization']);

        self::assertContains('organization', $result);
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
     * Test that an already-visited path is skipped while later fields are still
     * traversed.
     *
     * @return void
     */
    public function testBuildEagerLoadMapContinuesAfterVisitedPath(): void
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
     * Test that blank API-query fields for a child resource fall back to the
     * child's default fields during recursion.
     *
     * @return void
     */
    public function testBuildEagerLoadMapFallsBackToChildDefaultsWhenRequestedFieldsAreBlank(): void
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

        self::assertContains('rel', $result);
        self::assertContains('rel.organization', $result);
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
            UserResource::class . '|*'                         => [['relation' => 'defaults', 'column' => '*', 'function' => 'count']],
            UserResource::class . "|posts_count\0posts_sum_id" => [['relation' => 'requested', 'column' => '*', 'function' => 'count']],
        ]);

        self::assertSame('defaults', EagerLoadPlanner::buildAggregateMap(UserResource::class)[0]['relation']);
        self::assertSame('defaults', EagerLoadPlanner::buildAggregateMap(UserResource::class, [])[0]['relation']);
        self::assertSame('requested', EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts_count', 'posts_sum_id'])[0]['relation']);
        self::assertSame('posts as aggregate_posts_count', EagerLoadPlanner::buildAggregateMap(UserResource::class, ['posts_count'])[0]['relation']);
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
     * Test that a wrapped constraint runs against a MorphTo query verbatim, is
     * applied to a resolved builder, and is skipped for a query that resolves
     * to no builder.
     *
     * @return void
     */
    public function testWrappedConstraintHandlesMorphToBuilderAndNonBuilderQueries(): void
    {

        /** @var list<mixed> $calls */
        $calls = [];

        $constrainedResource = new class {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'wrap_constraint_test';

            /** @var (\Closure(mixed): void)|null */
            public static ?\Closure $constraint = null;

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    'items' => [
                        'relation'   => 'items',
                        'constraint' => self::$constraint,
                    ],
                ];
            }
        };

        $constrainedResource::$constraint = static function ($query) use (&$calls): void {
            $calls[] = $query;
        };

        $map     = EagerLoadPlanner::buildEagerLoadMap($constrainedResource::class, ['items']);
        $wrapped = $map['items'];

        self::assertInstanceOf(\Closure::class, $wrapped);

        // MorphTo and builder queries are constrained; others are skipped
        $morphTo = \Mockery::mock(MorphTo::class);
        $builder = User::query();

        $wrapped($morphTo);
        $wrapped($builder);
        $wrapped(new \stdClass);

        self::assertCount(2, $calls);
        self::assertSame($morphTo, $calls[0]);
        self::assertSame($builder, $calls[1]);
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
     * Test that the requested-child-field lookup returns an empty set for a
     * class that is not an ApiResource.
     *
     * @return void
     */
    public function testGetRequestedChildFieldsReturnsEmptyForNonResourceClass(): void
    {
        $result = $this->invokeStaticMethod(EagerLoadPlanner::class, 'getRequestedChildFields', \stdClass::class);

        self::assertSame([], $result);
    }

    /**
     * Test that explicit child fields are returned as a zero-indexed list with
     * blank entries stripped.
     *
     * @return void
     */
    public function testResolveChildFieldsReturnsZeroIndexedListWithBlanksRemoved(): void
    {
        $definition = new CompiledFieldDefinition(null, null, null, null, ['', 'tags'], null, [], [], [], []);

        $result = $this->invokeStaticMethod(EagerLoadPlanner::class, 'resolveChildFields', $definition, 'x');

        self::assertSame(['tags'], $result);
    }

    /**
     * Test that the requested child fields are returned as a zero-indexed list
     * with blank entries stripped.
     *
     * @return void
     */
    public function testGetRequestedChildFieldsReturnsZeroIndexedListWithBlanksRemoved(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['', 'name']);

        $result = $this->invokeStaticMethod(EagerLoadPlanner::class, 'getRequestedChildFields', UserResource::class);

        self::assertSame(['name'], $result);
    }

    /**
     * Test that a pair of resources naming each other stops instead of
     * descending without bound.
     *
     * The visited set is keyed by the lengthening path, so without a depth
     * bound a cycle would exhaust memory.
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
}
