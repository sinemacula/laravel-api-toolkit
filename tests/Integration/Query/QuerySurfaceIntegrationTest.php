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
     * Seed users and posts, and map Post to PostResource for nested gating.
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
     * Test that the allowlist is the default posture.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAllowlistIsTheDefaultPosture(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'], JSON_THROW_ON_ERROR)]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        $this->assertRejects(
            ['filters' => json_encode(['status' => 'active'], JSON_THROW_ON_ERROR)],
            'filters.status',
        );
    }

    /**
     * A declared filterable column is applied through an operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredFilterableColumnIsApplied(): void
    {
        $this->parseQuery(['filters' => json_encode(['email' => ['$eq' => 'bob@example.com']], JSON_THROW_ON_ERROR)]);

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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredFilterColumnIsRejectedFailClosed(): void
    {
        $this->assertRejects(
            ['filters' => json_encode(['status' => 'active'], JSON_THROW_ON_ERROR)],
            'filters.status',
        );
    }

    /**
     * A declared sortable column orders the results.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * Test that a filterable column is not implicitly sortable.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredTraversableRelationIsApplied(): void
    {
        $this->parseQuery(['filters' => json_encode(['$has' => 'posts'], JSON_THROW_ON_ERROR)]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(2, $results);
    }

    /**
     * An undeclared relation is rejected even though the model defines it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredRelationIsRejected(): void
    {
        $this->assertRejects(
            ['filters' => json_encode(['$has' => 'organization'], JSON_THROW_ON_ERROR)],
            'filters.organization',
        );
    }

    /**
     * Test that a declared nested relation column is permitted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredNestedRelationColumnIsPermittedUnderAllowlist(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'title' => ['$eq' => 'Alice Post'],
            ],
        ], JSON_THROW_ON_ERROR)]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * Test that an undeclared nested relation column is rejected.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredNestedRelationColumnIsRejectedUnderAllowlist(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'body' => 'Content',
            ],
        ], JSON_THROW_ON_ERROR)]);

        try {
            $this->declaredCriteria()->apply(new User);
            self::fail('Expected a ValidationException for undeclared nested column "body".');
        } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
            // apply() throws through the applier chain, out of static view
            self::assertArrayHasKey('filters.body', $e->errors());
        }
    }

    /**
     * Test that an undeclared nested relation traversal is rejected.
     *
     * The relation is real, but PostResource declares nothing traversable.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNestedUndeclaredRelationTraversalIsRejectedFailClosed(): void
    {
        $this->parseQuery(['filters' => json_encode([
            'posts' => [
                'nested' => ['user' => ['name' => 'Alice']],
            ],
        ], JSON_THROW_ON_ERROR)]);

        try {
            $this->declaredCriteria()->apply(new User);
            self::fail('Expected a ValidationException for undeclared nested relation "user".');
        } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
            // apply() throws through the applier chain, out of static view
            self::assertArrayHasKey('filters.user', $e->errors());
        }
    }

    /**
     * Test that a declared nested relation traversal is applied end-to-end.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
        ], JSON_THROW_ON_ERROR)]);

        $results = $this->declaredCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * Test that a model with no mapped resource rejects every key.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testModelWithoutMappedResourceRejectsEveryKey(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'], JSON_THROW_ON_ERROR)]);

        $this->expectException(ValidationException::class);

        $this->makeCriteria()->apply(new User);
    }

    /**
     * Assert that applying the parameters rejects with the named error.
     *
     * @param  array<string, string>  $params
     * @param  string  $expectedKey
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRejects(array $params, string $expectedKey): void
    {
        $this->parseQuery($params);

        try {
            $this->declaredCriteria()->apply(new User);
            self::fail('Expected a ValidationException for "' . $expectedKey . '".');
        } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
            // apply() throws through the applier chain, out of static view
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
