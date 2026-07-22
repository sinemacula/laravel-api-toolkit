<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\BetweenOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\NullableFilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests exercising the filter-operator matrix over real HTTP requests.
 *
 * Drives the comparison operators, the between range operator and its
 * malformed-payload no-op, the null and not-null presence operators against a
 * nullable filterable column, multi-clause AND composition, and the logical
 * grouping operators through the parsed query string, asserting the narrowed
 * rows and meta totals in the rendered envelope.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(NotEqualOperator::class)]
#[CoversClass(LessThanOperator::class)]
#[CoversClass(GreaterThanOrEqualOperator::class)]
#[CoversClass(LessThanOrEqualOperator::class)]
#[CoversClass(BetweenOperator::class)]
#[CoversClass(NullOperator::class)]
#[CoversClass(NotNullOperator::class)]
final class FilterOperatorMatrixTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up two repository-backed routes and seed five rows with a mix of
     * present and absent organization ids.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/nullable-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(NullableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, NullableFilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'organization_id' => null]);
        User::create(['name' => 'Alan', 'email' => 'alan@example.com', 'organization_id' => 10]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'organization_id' => null]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'organization_id' => 20]);
        User::create(['name' => 'Dave', 'email' => 'dave@example.com', 'organization_id' => null]);
    }

    /**
     * Test that the not-equal operator excludes the matching row.
     *
     * @return void
     */
    public function testNotEqualOperatorExcludesTheMatchingName(): void
    {
        $names = $this->names($this->query(['name' => ['$neq' => 'Alice']]));

        self::assertCount(4, $names);
        self::assertNotContains('Alice', $names);
        self::assertContains('Alan', $names);
    }

    /**
     * Test that the less-than operator narrows the result set by id.
     *
     * @return void
     */
    public function testLessThanOperatorNarrowsById(): void
    {
        $names = $this->names($this->query(['id' => ['$lt' => 3]]));

        self::assertEqualsCanonicalizing(['Alice', 'Alan'], $names);
    }

    /**
     * Test that the greater-than-or-equal operator narrows by id.
     *
     * @return void
     */
    public function testGreaterThanOrEqualOperatorNarrowsById(): void
    {
        $names = $this->names($this->query(['id' => ['$ge' => 4]]));

        self::assertEqualsCanonicalizing(['Carol', 'Dave'], $names);
    }

    /**
     * Test that the less-than-or-equal operator narrows the result set by id.
     *
     * @return void
     */
    public function testLessThanOrEqualOperatorNarrowsById(): void
    {
        $names = $this->names($this->query(['id' => ['$le' => 2]]));

        self::assertEqualsCanonicalizing(['Alice', 'Alan'], $names);
    }

    /**
     * Test that the between operator applies an inclusive range over ids.
     *
     * @return void
     */
    public function testBetweenOperatorAppliesAnInclusiveRange(): void
    {
        $names = $this->names($this->query(['id' => ['$between' => [2, 4]]]));

        self::assertEqualsCanonicalizing(['Alan', 'Bob', 'Carol'], $names);
    }

    /**
     * Test that a between payload with fewer than two bounds is dropped and
     * widens to the full set.
     *
     * @return void
     */
    public function testBetweenOperatorDropsAnUnderLongPayload(): void
    {
        $response = $this->query(['id' => ['$between' => [2]]]);

        $response->assertJsonPath('meta.total', 5);
        self::assertCount(5, $this->names($response));
    }

    /**
     * Test that a between payload with more than two bounds is dropped and
     * widens to the full set.
     *
     * @return void
     */
    public function testBetweenOperatorDropsAnOverLongPayload(): void
    {
        $response = $this->query(['id' => ['$between' => [2, 3, 4]]]);

        $response->assertJsonPath('meta.total', 5);
        self::assertCount(5, $this->names($response));
    }

    /**
     * Test that the null operator returns only rows with an absent value.
     *
     * @return void
     */
    public function testNullOperatorReturnsOnlyNullRows(): void
    {
        $names = $this->names($this->query(['organization_id' => ['$null' => true]], '/nullable-users'));

        self::assertEqualsCanonicalizing(['Alice', 'Bob', 'Dave'], $names);
    }

    /**
     * Test that the not-null operator returns only rows with a present value.
     *
     * @return void
     */
    public function testNotNullOperatorReturnsOnlyNonNullRows(): void
    {
        $names = $this->names($this->query(['organization_id' => ['$notNull' => true]], '/nullable-users'));

        self::assertEqualsCanonicalizing(['Alan', 'Carol'], $names);
    }

    /**
     * Test that the presence-operator payload boolean is ignored and only the
     * token decides the constraint.
     *
     * @return void
     */
    public function testNullOperatorIgnoresThePayloadBoolean(): void
    {
        $names = $this->names($this->query(['organization_id' => ['$null' => false]], '/nullable-users'));

        self::assertEqualsCanonicalizing(['Alice', 'Bob', 'Dave'], $names);
    }

    /**
     * Test that two distinct field-operator filters compose with AND semantics.
     *
     * @return void
     */
    public function testTwoFiltersComposeWithAndSemantics(): void
    {
        $names = $this->names($this->query([
            'name' => ['$like' => 'Al'],
            'id'   => ['$gt' => 1],
        ]));

        self::assertSame(['Alan'], $names);
    }

    /**
     * Test that the or grouping operator returns the union of both branches.
     *
     * @return void
     */
    public function testOrGroupingReturnsTheUnionOfBranches(): void
    {
        $names = $this->names($this->query([
            '$or' => [
                'name' => 'Alice',
                'id'   => ['$gt' => 4],
            ],
        ]));

        self::assertEqualsCanonicalizing(['Alice', 'Dave'], $names);
    }

    /**
     * Test that the and grouping operator returns the intersection of branches.
     *
     * @return void
     */
    public function testAndGroupingReturnsTheIntersectionOfBranches(): void
    {
        $names = $this->names($this->query([
            '$and' => [
                'name' => ['$like' => 'Al'],
                'id'   => ['$gt' => 1],
            ],
        ]));

        self::assertSame(['Alan'], $names);
    }

    /**
     * Issue a filtered request against the given route and return the response.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $route
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function query(array $filters, string $route = '/users'): TestResponse
    {
        $response = $this->getJson($route . '?filters=' . urlencode((string) json_encode($filters)));

        $response->assertOk();

        return $response;
    }

    /**
     * Extract the name column from a response data payload.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return array<int, string>
     */
    private function names(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'name');
    }
}
