<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the EagerLoadPlanner eager-load map and count map builder.
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

        static::assertSame([], $result);
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

        static::assertContains('organization', $result);
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

        static::assertArrayHasKey('items', $result);
        static::assertInstanceOf(\Closure::class, $result['items']);
    }

    /**
     * Test that child resource relations are recursed into and prefixed.
     *
     * When the API query requests relation fields for the child resource,
     * the planner recurses into those fields and prefixes the paths.
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

        static::assertContains('posts', $result);
        static::assertContains('posts.user', $result);
        static::assertContains('posts.tags', $result);
    }

    /**
     * Test that extra eager-load paths from the definition are included.
     *
     * @return void
     */
    public function testBuildEagerLoadMapIncludesExtraPaths(): void
    {

        $resourceWithExtras = new class {
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

        static::assertContains('media', $result);
        static::assertContains('media.thumbnails', $result);
    }

    /**
     * Test that visited resource/path pairs are not traversed again.
     *
     * When a resource class references the same relation path that has
     * already been visited, the planner skips it to prevent duplicate
     * entries and unbounded traversal.
     *
     * @return void
     */
    public function testBuildEagerLoadMapPreventsCyclicTraversal(): void
    {

        $resourceWithDuplicatePaths = new class {
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

        static::assertSame(1, $itemsCount);
    }

    /**
     * Test that default counts are included when no aliases are requested.
     *
     * @return void
     */
    public function testBuildCountMapReturnsDefaultCountsWhenNoAliasesRequested(): void
    {
        $result = EagerLoadPlanner::buildCountMap(UserResource::class);

        static::assertContains('posts as posts_count', $result);
    }

    /**
     * Test that only explicitly requested count aliases appear in the map.
     *
     * @return void
     */
    public function testBuildCountMapReturnsOnlyRequestedAliases(): void
    {
        $result = EagerLoadPlanner::buildCountMap(UserResource::class, ['posts']);

        static::assertContains('posts as posts_count', $result);

        $nonDefaultResult = EagerLoadPlanner::buildCountMap(OrganizationResource::class, ['users']);

        static::assertContains('users as users_count', $nonDefaultResult);
    }

    /**
     * Test that a count with a constraint produces an associative entry.
     *
     * @return void
     */
    public function testBuildCountMapReturnsScopedCountForConstrainedCount(): void
    {

        $constrainedCountResource = new class {
            public const string RESOURCE_TYPE = 'constrained_count_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    '__count__:active_posts' => [
                        'key'        => 'active_posts',
                        'metric'     => 'count',
                        'relation'   => 'posts',
                        'constraint' => fn ($query) => $query->where('published', true),
                        'default'    => true,
                    ],
                ];
            }
        };

        $resourceClass = $constrainedCountResource::class;
        $result        = EagerLoadPlanner::buildCountMap($resourceClass);

        static::assertArrayHasKey('posts as active_posts_count', $result);
        static::assertInstanceOf(\Closure::class, $result['posts as active_posts_count']);
    }

    /**
     * Test that a schema with no count definitions returns an empty array.
     *
     * @return void
     */
    public function testBuildCountMapReturnsEmptyWhenNoCountsDefined(): void
    {
        $result = EagerLoadPlanner::buildCountMap(TagResource::class);

        static::assertSame([], $result);
    }

    /**
     * Test that non-default counts are excluded when no aliases are requested.
     *
     * @return void
     */
    public function testBuildCountMapExcludesNonDefaultCountsWhenNoAliasesRequested(): void
    {
        $result = EagerLoadPlanner::buildCountMap(OrganizationResource::class);

        static::assertNotContains('users', $result);
        static::assertSame([], $result);
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

        static::assertSame([], $result);
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

        static::assertContains('organization', $result);
    }

    /**
     * Test that the API query fields for a child resource type are used when
     * no explicit fields are defined on the relation.
     *
     * @return void
     */
    public function testBuildEagerLoadMapUsesApiQueryFieldsForChildResource(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(['id', 'name', 'slug']);

        $result = EagerLoadPlanner::buildEagerLoadMap(UserResource::class, ['organization']);

        static::assertContains('organization', $result);
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

        static::assertSame($first, $second);
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
     * Test that a memoised count map is returned without rebuilding.
     *
     * @return void
     */
    public function testBuildCountMapReturnsMemoisedResult(): void
    {
        $this->setStaticProperty(EagerLoadPlanner::class, 'countCache', [UserResource::class . '|*' => ['sentinel_count']]);

        $result = EagerLoadPlanner::buildCountMap(UserResource::class);

        static::assertSame(['sentinel_count'], $result);
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

        static::assertSame(['sentinel_relation'], $result);
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

        static::assertContains('plain', $result);
        static::assertArrayHasKey('scoped', $result);
        static::assertInstanceOf(\Closure::class, $result['scoped']);
    }

    /**
     * Test that count definitions after an excluded one are still evaluated
     * and that every matching count is returned.
     *
     * @return void
     */
    public function testBuildCountMapContinuesPastExcludedCounts(): void
    {

        $multiCountResource = new class {
            public const string RESOURCE_TYPE = 'multi_count_test';

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [
                    '__count__:drafts' => [
                        'metric'   => 'count',
                        'relation' => 'drafts',
                    ],
                    '__count__:published' => [
                        'metric'   => 'count',
                        'relation' => 'published',
                        'default'  => true,
                    ],
                    '__count__:archived' => [
                        'metric'   => 'count',
                        'relation' => 'archived',
                        'default'  => true,
                    ],
                ];
            }
        };

        $resourceClass = $multiCountResource::class;
        $result        = EagerLoadPlanner::buildCountMap($resourceClass);

        static::assertSame(['published as published_count', 'archived as archived_count'], array_values($result));
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

        static::assertContains('organization', $result);
    }

    /**
     * Test that an already-visited path is skipped while later fields are
     * still traversed.
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

        static::assertContains('organization', $result);
        static::assertContains('posts', $result);
    }

    /**
     * Test that relations pointing at non-ApiResource classes are not
     * recursed into.
     *
     * @return void
     */
    public function testBuildEagerLoadMapDoesNotRecurseIntoNonResourceClasses(): void
    {

        $nonResourceChild = new class {
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

        static::assertSame(['organization'], $result);
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

        static::assertContains('posts', $result);
        static::assertContains('posts.tags', $result);
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
            public const string RESOURCE_TYPE = 'blank_fields_child';

            /** @var array<int, string> */
            protected static array $default = ['organization'];

            /**
             * @return array<string, array<string, mixed>>
             */
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

        static::assertContains('rel', $result);
        static::assertContains('rel.organization', $result);
    }
}
