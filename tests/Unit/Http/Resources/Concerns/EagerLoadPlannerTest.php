<?php

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;
use Tests\Fixtures\Resources\OrganizationResource;
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
class EagerLoadPlannerTest extends TestCase
{
    /**
     * Clear the schema compiler cache before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();
    }

    /**
     * Clear the schema compiler cache after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

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

        static::assertContains('posts', $result);
    }

    /**
     * Test that only explicitly requested count aliases appear in the map.
     *
     * @return void
     */
    public function testBuildCountMapReturnsOnlyRequestedAliases(): void
    {
        $result = EagerLoadPlanner::buildCountMap(UserResource::class, ['posts']);

        static::assertContains('posts', $result);

        $nonDefaultResult = EagerLoadPlanner::buildCountMap(OrganizationResource::class, ['users']);

        static::assertContains('users', $nonDefaultResult);
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

        static::assertArrayHasKey('posts', $result);
        static::assertInstanceOf(\Closure::class, $result['posts']);
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
}
