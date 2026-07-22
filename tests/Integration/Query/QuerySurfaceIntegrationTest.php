<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\DeepTraversalPostResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Integration tests for the allowlist query-surface posture end-to-end.
 *
 * Exercises the secure-by-default posture against a real database: declared
 * filterable/sortable columns and traversable relations are applied, while
 * undeclared keys on the root resource are rejected (fail-closed) or dropped
 * (fail-quiet). Under the allowlist posture, nested columns on a traversed
 * relation are gated against the related resource's declared filterable set
 * -not against the legacy isSearchable predicate. When no mapped resource
 * exists for a related model the gate fails closed. A model with no mapped
 * resource rejects every key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(QuerySurface::class)]
final class QuerySurfaceIntegrationTest extends TestCase
{
    /**
     * Seed users and posts for integration tests, and configure the resource
     * map so related-model column gating can resolve Post to PostResource.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.resource_map', [
            Post::class => PostResource::class,
        ]);

        $this->seedData();
    }

    /**
     * The allowlist posture is the default: a declared column is applied while
     * an undeclared column on the same resource is rejected, with no posture
     * configuration set.
     *
     * @return void
     */
    public function testAllowlistIsTheDefaultPosture(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        $this->assertRejects(
            ['filters' => json_encode(['status' => 'active'])],
            'filters.status',
        );
    }

    /**
     * A declared filterable column is applied through an operator.
     *
     * @return void
     */
    public function testDeclaredFilterableColumnIsApplied(): void
    {
        $this->parseQuery(['filters' => json_encode(['email' => ['$like' => 'bob@']])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Bob', $first->name);
    }

    /**
     * An undeclared filter column is rejected with a named validation error.
     *
     * @return void
     */
    public function testUndeclaredFilterColumnIsRejectedFailClosed(): void
    {
        $this->assertRejects(
            ['filters' => json_encode(['status' => 'active'])],
            'filters.status',
        );
    }

    /**
     * A declared sortable column orders the results.
     *
     * @return void
     */
    public function testDeclaredSortableColumnIsApplied(): void
    {
        $this->parseQuery(['order' => 'name:asc']);

        $results = $this->declaredCriteria()->apply(new User)->get();

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        /** @var \Tests\Fixtures\Models\User $last */
        $last = $results->last();

        self::assertSame('Alice', $first->name);
        self::assertSame('Charlie', $last->name);
    }

    /**
     * A column that is declared filterable but not sortable is rejected when
     * used for ordering: the filterable and sortable sets are independent.
     *
     * @return void
     */
    public function testFilterableColumnIsNotImplicitlySortable(): void
    {
        $this->assertRejects(
            ['order' => 'email:asc'],
            'order.email',
        );
    }

    /**
     * A declared traversable relation is applied through the $has operator.
     *
     * @return void
     */
    public function testDeclaredTraversableRelationIsApplied(): void
    {
        $this->parseQuery(['filters' => json_encode(['$has' => 'posts'])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        // Only Alice and Bob have posts.
        self::assertCount(2, $results);
    }

    /**
     * An undeclared relation is rejected even though the model defines it.
     *
     * @return void
     */
    public function testUndeclaredRelationIsRejected(): void
    {
        $this->assertRejects(
            ['filters' => json_encode(['$has' => 'organization'])],
            'filters.organization',
        );
    }

    /**
     * Under the allowlist posture a column declared filterable in the related
     * resource's schema is permitted and the SQL constraint is applied.
     *
     * PostResource declares 'title' as filterable; filtering posts.title must
     * return only the user whose post title matches.
     *
     * @return void
     */
    public function testDeclaredNestedRelationColumnIsPermittedUnderAllowlist(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'title' => ['$like' => 'Alice'],
            ],
        ])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * Under the allowlist posture a column that is NOT declared filterable in
     * the related resource's schema is rejected with a ValidationException.
     *
     * PostResource does not declare 'body' as filterable, so filtering on
     * posts.body must throw.
     *
     * @return void
     */
    public function testUndeclaredNestedRelationColumnIsRejectedUnderAllowlist(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'body' => 'Content',
            ],
        ])]);

        try {
            $this->declaredCriteria()->apply(new User);
            self::fail('Expected a ValidationException for undeclared nested column "body".');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('filters.body', $e->errors());
        }
    }

    /**
     * Under the allowlist posture a relation traversed at a nested hop is gated
     * against the related resource's declared traversable set, not merely
     * whether the relation exists.
     *
     * PostResource declares no traversable relations, so pivoting from a
     * traversed post into its 'user' relation must be rejected fail-closed even
     * though the relation is real.
     *
     * @return void
     */
    public function testNestedUndeclaredRelationTraversalIsRejectedFailClosed(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'nested' => ['user' => ['name' => 'Alice']],
            ],
        ])]);

        try {
            $this->declaredCriteria()->apply(new User);
            self::fail('Expected a ValidationException for undeclared nested relation "user".');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('filters.user', $e->errors());
        }
    }

    /**
     * With fail-quiet rejection an undeclared nested relation is dropped rather
     * than applied: the onward hop adds no constraint and no exception escapes.
     *
     * PostResource does not declare 'user' traversable, so under fail-quiet the
     * nested 'user' constraint is dropped and only the outer 'posts' existence
     * applies - the two users with posts remain (contrast the
     * declared-traversal case, which narrows the same query to one).
     *
     * @return void
     */
    public function testNestedUndeclaredRelationTraversalIsDroppedFailQuiet(): void
    {
        Config::set('api-toolkit.repositories.reject_undeclared', false);

        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'nested' => ['user' => ['name' => 'Alice']],
            ],
        ])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(2, $results);
    }

    /**
     * Under the allowlist posture a nested relation that the related resource
     * declares traversable is applied end-to-end.
     *
     * DeepTraversalPostResource declares 'user' traversable, so the chain
     * users -> posts -> user is permitted at every hop and the whereHas nesting
     * is built: only Alice owns a post whose user is named Alice.
     *
     * @return void
     */
    public function testDeclaredNestedRelationTraversalIsAppliedUnderAllowlist(): void
    {
        Config::set('api-toolkit.resources.resource_map', [
            Post::class => DeepTraversalPostResource::class,
        ]);

        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'nested' => ['user' => ['name' => 'Alice']],
            ],
        ])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * With fail-quiet rejection an undeclared key is silently dropped: it adds
     * no constraint and no exception escapes.
     *
     * @return void
     */
    public function testFailQuietDropsUndeclaredKey(): void
    {
        Config::set('api-toolkit.repositories.reject_undeclared', false);

        $this->parseQuery(['filters' => json_encode(['status' => 'active'])]);

        $query   = $this->declaredCriteria()->apply(new User);
        $results = $query->get();

        self::assertEmpty($query->getQuery()->wheres);
        self::assertCount(3, $results);
    }

    /**
     * A model with no mapped resource yields an empty surface, so the allowlist
     * posture rejects every key - secure by default.
     *
     * @return void
     */
    public function testModelWithoutMappedResourceRejectsEveryKey(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'])]);

        $this->expectException(ValidationException::class);

        // No usingResource() and no resource_map entry for User: surface empty.
        $this->makeCriteria()->apply(new User);
    }

    /**
     * Assert that applying the given query parameters rejects with a named
     * validation error under the allowlist posture.
     *
     * @param  array<string, string>  $params
     * @param  string  $expectedKey
     * @return void
     */
    private function assertRejects(array $params, string $expectedKey): void
    {
        $this->parseQuery($params);

        try {
            $this->declaredCriteria()->apply(new User);
            self::fail('Expected a ValidationException for "' . $expectedKey . '".');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($expectedKey, $e->errors());
        }
    }

    /**
     * Parse query parameters through the ApiQuery facade.
     *
     * @param  array<string, string>  $params
     * @return void
     */
    private function parseQuery(array $params): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb(), $params);

        ApiQuery::parse($request);
    }

    /**
     * Resolve a fresh ApiCriteria bound to the declared-surface resource.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function declaredCriteria(): ApiCriteria
    {
        return $this->makeCriteria()->usingResource(FilterableUserResource::class);
    }

    /**
     * Resolve a fresh ApiCriteria instance from the container.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function makeCriteria(): ApiCriteria
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria */
        return $this->app->make(ApiCriteria::class);
    }

    /**
     * Seed the database with test data.
     *
     * @return void
     */
    private function seedData(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content', 'published' => false]);
    }
}
