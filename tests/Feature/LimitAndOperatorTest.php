<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for limit clamping and filter-operator grammar over HTTP.
 *
 * A client-supplied page limit above the configured ceiling is clamped down to
 * the ceiling rather than honoured, and a small sample of declared filter
 * operators - greater-than, like, and in - each narrow the result set through
 * the parsed query string.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(FilterApplier::class)]
final class LimitAndOperatorTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Alan', 'email' => 'alan@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com']);
        User::create(['name' => 'Dave', 'email' => 'dave@example.com']);
    }

    /**
     * Test that a per-page limit above the configured ceiling is clamped to the
     * ceiling.
     *
     * @return void
     */
    public function testLimitAboveTheCeilingIsClampedToTheCeiling(): void
    {
        Config::set('api-toolkit.parser.max_limit', 2);

        $response = $this->getJson('/api/users?limit=50');

        $response->assertOk();

        // Clamped to the ceiling of two despite the requested fifty, while the
        // meta still reports every seeded row as the total.
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.continue', true);
    }

    /**
     * Test that the greater-than operator narrows the result set by id.
     *
     * @return void
     */
    public function testGreaterThanOperatorNarrowsById(): void
    {
        $filters = json_encode(['id' => ['$gt' => 3]]);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $names = array_column((array) $response->json('data'), 'name');

        self::assertContains('Carol', $names);
        self::assertContains('Dave', $names);
        self::assertNotContains('Alice', $names);
    }

    /**
     * Test that the like operator narrows the result set by a name substring.
     *
     * @return void
     */
    public function testLikeOperatorNarrowsByName(): void
    {
        $filters = json_encode(['name' => ['$like' => 'Al']]);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $names = array_column((array) $response->json('data'), 'name');

        self::assertContains('Alice', $names);
        self::assertContains('Alan', $names);
        self::assertNotContains('Bob', $names);
    }

    /**
     * Test that the in operator narrows the result set to a set of names.
     *
     * @return void
     */
    public function testInOperatorNarrowsByNameSet(): void
    {
        $filters = json_encode(['name' => ['$in' => ['Bob', 'Dave']]]);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $names = array_column((array) $response->json('data'), 'name');

        self::assertContains('Bob', $names);
        self::assertContains('Dave', $names);
        self::assertNotContains('Alice', $names);
    }
}
