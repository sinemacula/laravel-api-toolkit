<?php

namespace Tests\Integration;

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
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Integration tests for the allowlist query-surface posture end-to-end.
 *
 * Exercises the secure-by-default posture against a real database: declared
 * filterable/sortable columns and traversable relations are applied, while
 * undeclared keys on the root resource are rejected (fail-closed) or dropped
 * (fail-quiet). Nested columns on a traversed relation fall back to the legacy
 * searchable predicate, and a model with no mapped resource rejects every key.
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
     * Seed users and posts for integration tests.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

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

        static::assertCount(1, $results);

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

        static::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        static::assertSame('Bob', $first->name);
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

        static::assertSame('Alice', $first->name);
        static::assertSame('Charlie', $last->name);
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
        static::assertCount(2, $results);
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
     * A column on a declared-traversable relation falls back to the legacy
     * searchable predicate on the related model, so nested filters still work
     * without a per-relation column declaration.
     *
     * @return void
     */
    public function testNestedRelationColumnFallsBackToLegacySearchable(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'title' => ['$like' => 'Alice'],
            ],
        ])]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        static::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        static::assertSame('Alice', $first->name);
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

        static::assertEmpty($query->getQuery()->wheres);
        static::assertCount(3, $results);
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

        // No usingResource() and no resource_map entry: the surface is empty.
        $this->makeCriteria()->apply(new User);
    }

    /**
     * Cross-surface coherence: every field hidden from exports must also be
     * excluded from filtering under the blocklist posture, so a field the API
     * never serialises cannot be probed through a filter.
     *
     * @return void
     */
    public function testSensitiveExportFieldsAreExcludedFromBlocklistFiltering(): void
    {
        /** @var array<int, string> $ignored */
        $ignored = Config::get('api-toolkit.exports.ignored_fields', []);

        /** @var array<int, string> $excluded */
        $excluded = Config::get('api-toolkit.repositories.searchable_exclusions', []);

        foreach ($ignored as $field) {

            if ($field === '_type') {
                continue;
            }

            static::assertContains($field, $excluded, sprintf(
                'Export-ignored field "%s" must also be excluded from filtering.',
                $field,
            ));
        }
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
            static::fail('Expected a ValidationException for "' . $expectedKey . '".');
        } catch (ValidationException $e) {
            static::assertArrayHasKey($expectedKey, $e->errors());
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
