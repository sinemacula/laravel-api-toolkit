<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiCriteriaIntegrationTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function testCriteriaApplyCombinesFiltersEagerLoadingLimitAndOrder(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);

        $firstUser = User::query()->create([
            'name'            => 'Alice',
            'organization_id' => $organization->id,
            'active'          => true,
            'age'             => 33,
            'meta'            => ['role' => 'admin'],
        ]);

        User::query()->create([
            'name'            => 'Bob',
            'organization_id' => $organization->id,
            'active'          => false,
            'age'             => 22,
            'meta'            => ['role' => 'user'],
        ]);

        Post::query()->create(['user_id' => $firstUser->id, 'title' => 'Post A', 'published' => true]);
        Post::query()->create(['user_id' => $firstUser->id, 'title' => 'Post B', 'published' => false]);

        $criteria = $this->makeCriteria([
            'fields'  => ['user' => 'id,organization,posts,counts'],
            'counts'  => ['user' => 'posts'],
            'filters' => json_encode([
                'id'  => ['$gt' => 0],
                '$or' => [
                    'active' => ['$eq' => true],
                    'age'    => ['$gt' => 20],
                ],
                '$has' => ['posts'],
            ]),
            'order' => 'random:asc,name:desc',
            'limit' => 1,
        ]);

        $query = $criteria->apply(User::query());

        static::assertSame(1, $query->getQuery()->limit);
        static::assertArrayHasKey('organization', $query->getEagerLoads());
        static::assertArrayHasKey('posts', $query->getEagerLoads());

        $result = $query->first();

        static::assertNotNull($result);
        static::assertTrue(isset($result->posts_count));
    }

    public function testCriteriaApplyEagerLoadingBranchesForInvalidEmptyAndValidResources(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);
        $user         = User::query()->create(['name' => 'Alice', 'organization_id' => $organization->id]);
        Post::query()->create(['user_id' => $user->id, 'title' => 'Post', 'published' => true]);

        $invalidCriteria = $this->makeCriteria([
            'fields' => ['user' => 'organization,posts'],
            'counts' => ['user' => 'posts'],
        ]);

        $invalidCriteria->usingResource(\stdClass::class);

        $invalidQuery = $this->invokeNonPublic($invalidCriteria, 'applyEagerLoading', User::query());
        static::assertSame([], $invalidQuery->getEagerLoads());

        $emptyCriteria = $this->makeCriteria([]);
        $emptyCriteria->usingResource(EmptyFieldsResource::class);

        $emptyQuery = $this->invokeNonPublic($emptyCriteria, 'applyEagerLoading', User::query());
        static::assertSame([], $emptyQuery->getEagerLoads());

        $validCriteria = $this->makeCriteria([
            'fields' => ['user' => 'organization,posts,counts'],
            'counts' => ['user' => 'posts'],
        ]);

        $validQuery = $this->invokeNonPublic($validCriteria, 'applyEagerLoading', User::query());

        static::assertArrayHasKey('organization', $validQuery->getEagerLoads());
        static::assertArrayHasKey('posts', $validQuery->getEagerLoads());
        static::assertTrue(isset($validQuery->first()?->posts_count));

        $allFieldsCriteria = $this->makeCriteria([
            'fields' => ['user' => ':all'],
            'counts' => ['user' => 'posts'],
        ]);

        $allFieldsQuery = $this->invokeNonPublic($allFieldsCriteria, 'applyEagerLoading', User::query());
        static::assertNotSame([], $allFieldsQuery->getEagerLoads());
    }

    public function testCriteriaHelperMethodsAndOperatorBranchesAreExercisedViaReflection(): void
    {
        $organization = Organization::query()->create(['name' => 'Org']);

        $user = User::query()->create([
            'name'            => 'Alice',
            'organization_id' => $organization->id,
            'active'          => true,
            'age'             => 30,
            'meta'            => ['role' => 'admin'],
        ]);

        Post::query()->create(['user_id' => $user->id, 'title' => 'Post A', 'published' => true]);

        Config::set('api-toolkit.repositories.searchable_exclusions', ['password', 'users.deleted_at', 'posts.title']);

        $criteria = $this->makeCriteria([
            'filters' => json_encode(['name' => ['$like' => 'Ali']]),
            'order'   => 'name:desc',
            'limit'   => 5,
        ]);

        $query = User::query();

        $this->invokeNonPublic($criteria, 'applyFilters', $query, null);
        $this->invokeNonPublic($criteria, 'applyFilters', $query, 'Alice', 'name', '$and');
        $this->invokeNonPublic($criteria, 'applyFilters', $query, [
            '$eq'   => 'Alice',
            '$or'   => ['active' => ['$eq' => true]],
            'posts' => ['title' => ['$like' => 'Post']],
            'name'  => 'Alice',
        ], 'name', null);

        $this->invokeNonPublic($criteria, 'applyConditionOperator', $query, '$in', [1, 2], 'id', null);
        $this->invokeNonPublic($criteria, 'applyConditionOperator', $query, '$between', [1, 3], 'id', null);
        $this->invokeNonPublic($criteria, 'applyConditionOperator', $query, '$contains', ['role' => 'admin'], 'meta', null);
        $this->invokeNonPublic($criteria, 'applyConditionOperator', $query, '$null', null, 'updated_at', '$and');
        $this->invokeNonPublic($criteria, 'applyConditionOperator', $query, '$notNull', null, 'created_at', '$or');
        $this->invokeNonPublic($criteria, 'applyConditionOperator', $query, '$has', ['posts'], null, '$or');

        $this->invokeNonPublic($criteria, 'applyLogicalOperator', $query, '$or', [
            '$eq'  => 'ignored',
            '$and' => ['id' => ['$gt' => 0]],
            'name' => 'Alice',
        ], '$and');

        $this->invokeNonPublic($criteria, 'applyRelationFilter', $query, 'posts', ['title' => ['$like' => 'Post']], '$or');
        $this->invokeNonPublic($criteria, 'processRelationFilters', $query, [
            '$or' => ['title' => ['$like' => 'Post']],
        ]);
        $this->invokeNonPublic($criteria, 'processRelationFilters', $query, [
            'title' => ['$like' => 'Post'],
        ]);

        $this->invokeNonPublic($criteria, 'applyHasFilter', $query, ['posts'], '$has', null);
        $this->invokeNonPublic($criteria, 'applyHasFilter', $query, ['missingRelation'], '$has', null);
        $this->invokeNonPublic($criteria, 'applyHasFilter', $query, [
            'posts' => ['title' => ['$like' => 'Post']],
        ], '$hasnt', null);

        static::assertTrue($this->invokeNonPublic($criteria, 'isConditionOperator', '$eq'));
        static::assertFalse($this->invokeNonPublic($criteria, 'isConditionOperator', '$unknown'));
        static::assertTrue($this->invokeNonPublic($criteria, 'isLogicalOperator', '$or'));
        static::assertFalse($this->invokeNonPublic($criteria, 'isLogicalOperator', '$unknown'));

        $this->invokeNonPublic($criteria, 'handleCondition', $query, '$eq', 'Alice', 'name', '$and');
        $this->invokeNonPublic($criteria, 'handleCondition', $query, '$eq', 'Alice', 'missing_column', '$and');

        $this->invokeNonPublic($criteria, 'applyJsonContains', $query, 'meta', ['role' => 'admin']);
        $this->invokeNonPublic($criteria, 'applyJsonContains', $query, 'meta', 'admin,user');

        $throwingBuilder = \Mockery::mock(BuilderContract::class);
        $throwingBuilder->shouldReceive('whereJsonContains')->once()->andThrow(new \RuntimeException('forced'));
        $this->invokeNonPublic($criteria, 'applyJsonContains', $throwingBuilder, 'meta', 'plain-string');

        static::assertTrue($this->invokeNonPublic($criteria, 'isValidJson', '{"ok":true}'));
        static::assertFalse($this->invokeNonPublic($criteria, 'isValidJson', ''));

        $this->invokeNonPublic($criteria, 'applyNullCondition', $query, 'updated_at', true, '$and');
        $this->invokeNonPublic($criteria, 'applyNullCondition', $query, 'updated_at', false, '$or');
        $this->invokeNonPublic($criteria, 'applyDefaultCondition', $query, 'name', '$like', 'Ali', '$and');

        static::assertSame('where', $this->invokeNonPublic($criteria, 'determineLogicalMethod', '$or', '$and'));
        static::assertSame('orWhere', $this->invokeNonPublic($criteria, 'determineLogicalMethod', '$or', null));

        static::assertTrue($this->invokeNonPublic($criteria, 'isColumnSearchable', new User, 'name', 'asc'));
        static::assertFalse($this->invokeNonPublic($criteria, 'isColumnSearchable', new User, 'name', 'sideways'));

        static::assertSame(['name' => ['$like' => 'Ali']], $this->invokeNonPublic($criteria, 'getFilters'));
        static::assertSame(5, $this->invokeNonPublic($criteria, 'getLimit'));
        static::assertSame(['name' => 'desc'], $this->invokeNonPublic($criteria, 'getOrder'));

        $this->invokeNonPublic($criteria, 'applySimpleFilter', User::query(), 'name', 'Alice', '$and');
        $this->invokeNonPublic($criteria, 'applySimpleFilter', User::query(), 'not_searchable', 'Alice', '$and');

        $this->invokeNonPublic($criteria, 'applyOrder', User::query(), []);
        $orderedQuery = $this->invokeNonPublic($criteria, 'applyOrder', User::query(), [
            ApiCriteria::ORDER_BY_RANDOM => 'asc',
            'name'                       => 'desc',
            'missing'                    => 'asc',
        ]);
        static::assertNotNull($orderedQuery);

        $this->invokeNonPublic($criteria, 'applyLimit', $query, null);
        $limitedQuery = $this->invokeNonPublic($criteria, 'applyLimit', User::query(), 2);
        static::assertSame(2, $limitedQuery->getQuery()->limit);

        static::assertTrue($this->invokeNonPublic($criteria, 'isRelation', 'organization', new User));
        static::assertFalse($this->invokeNonPublic($criteria, 'isRelation', 'missingRelation', new User));
        static::assertFalse($this->invokeNonPublic($criteria, 'isRelation', 'explodingRelation', new User));

        $searchable      = $this->invokeNonPublic($criteria, 'getSearchableColumns', new User);
        $searchableAgain = $this->invokeNonPublic($criteria, 'getSearchableColumns', new User);

        static::assertSame($searchable, $searchableAgain);
        static::assertNotContains('deleted_at', $searchable);

        static::assertSame('%term%', $this->invokeNonPublic($criteria, 'formatValueBasedOnOperator', 'term', '$like'));
        static::assertSame('term', $this->invokeNonPublic($criteria, 'formatValueBasedOnOperator', 'term', '$eq'));

        $this->invokeNonPublic($criteria, 'applyBetween', $query, 'id', [1, 3]);
        $this->invokeNonPublic($criteria, 'applyBetween', $query, 'id', [1]);

        $resolvedSearchable = $this->invokeNonPublic($criteria, 'resolveSearchableColumns', new User);
        static::assertNotEmpty($resolvedSearchable);

        $exclusions = $this->invokeNonPublic($criteria, 'getColumnExclusions', 'users');
        static::assertContains('password', $exclusions);
        static::assertContains('deleted_at', $exclusions);
        static::assertContains('posts.title', $exclusions);
    }

    public function testCriteriaTraitsResolveResourceAndModelSchemaCachesAreCovered(): void
    {
        Config::set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $criteria = $this->makeCriteria([]);
        $model    = new User;

        $criteria->usingResource(EmptyFieldsResource::class);
        static::assertSame(EmptyFieldsResource::class, $this->invokeNonPublic($criteria, 'resolveResource', $model));

        $criteria->usingResource(null);
        Cache::flush();

        static::assertSame(UserResource::class, $this->invokeNonPublic($criteria, 'getResourceFromModel', $model));

        Config::set('api-toolkit.resources.resource_map', [
            User::class => \stdClass::class,
        ]);

        Cache::flush();
        $invalidMappedCriteria = $this->makeCriteria([]);

        static::assertNull($this->invokeNonPublic($invalidMappedCriteria, 'getResourceFromModel', $model));

        Cache::flush();
        $columns = $this->invokeNonPublic($criteria, 'getColumnsFromModel', $model);

        static::assertContains('name', $columns);
        static::assertSame($columns, $this->invokeNonPublic($criteria, 'getColumnsFromModel', $model));
        static::assertSame($columns, $this->invokeNonPublic($criteria, 'resolveColumnsFromCacheForModel', $model));

        Cache::flush();
        $this->invokeNonPublic($criteria, 'storeColumnsInCacheForModel', ['id', 'name'], $model);
        $cachedColumns = $this->invokeNonPublic($criteria, 'resolveColumnsFromCacheForModel', $model);
        static::assertContains('id', $cachedColumns);
        static::assertContains('name', $cachedColumns);

        $newCriteria     = $this->makeCriteria([]);
        $resolvedColumns = $this->invokeNonPublic($newCriteria, 'resolveColumnsFromModel', $model);
        static::assertContains('id', $resolvedColumns);
        static::assertContains('name', $resolvedColumns);
    }

    private function makeCriteria(array $queryParameters): ApiCriteria
    {
        $request = HttpRequest::create('/api/users', 'GET', $queryParameters);

        $this->app->instance('request', $request);
        ApiQuery::parse($request);

        return new ApiCriteria($request);
    }
}

class EmptyFieldsResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'empty-fields';
    protected static array $default   = [];

    public static function schema(): array
    {
        return [];
    }
}
