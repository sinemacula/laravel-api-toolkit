<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for the random-ordering keyword through the kernel.
 *
 * The `random` keyword bypasses the sortable-column guard and applies a random
 * ordering rather than a column sort, so the request still returns the complete
 * seeded set. Order is non-deterministic, so the assertions are order-agnostic.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(OrderApplier::class)]
final class SortEdgeCaseTest extends TestCase
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

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::create(['name' => 'Ben', 'email' => 'ben@example.com']);
        User::create(['name' => 'Cara', 'email' => 'cara@example.com']);
        User::create(['name' => 'Dan', 'email' => 'dan@example.com']);
        User::create(['name' => 'Evan', 'email' => 'evan@example.com']);
    }

    /**
     * Test that random ordering returns the full seeded set regardless of the
     * resulting order.
     *
     * @return void
     */
    public function testRandomOrderReturnsTheFullSet(): void
    {
        $response = $this->getJson('/users?order=random');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.total', 5);

        $names = array_column((array) $response->json('data'), 'name');

        sort($names);

        self::assertSame(['Anna', 'Ben', 'Cara', 'Dan', 'Evan'], $names);
    }
}
