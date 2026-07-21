<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test combining every read modifier in a single request.
 *
 * Under the default allowlist posture a real request narrows the fieldset,
 * applies a declared filter, orders by a declared sortable column, and
 * truncates with a page limit. The four modifiers are proven to apply together:
 * the fieldset drops undeclared keys, the filtered total excludes non-matching
 * rows, the ordering fixes row positions, and the limit caps the page while the
 * meta still reports the filtered total.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(ApiResourceCollection::class)]
final class CombinedRequestTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded rows.
     *
     * Four rows carry the retained email domain and two carry a dropped domain
     * whose names sort ahead of every retained row, so a missing filter would
     * surface them first under the descending sort.
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

        User::create(['name' => 'Alpha', 'email' => 'alpha@keep.com']);
        User::create(['name' => 'Bravo', 'email' => 'bravo@keep.com']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@keep.com']);
        User::create(['name' => 'Delta', 'email' => 'delta@keep.com']);
        User::create(['name' => 'Yankee', 'email' => 'yankee@drop.com']);
        User::create(['name' => 'Zulu', 'email' => 'zulu@drop.com']);
    }

    /**
     * Test that a fieldset, a filter, a sort, and a limit apply together in one
     * request.
     *
     * @return void
     */
    public function testFieldsFilterSortAndLimitApplyTogether(): void
    {
        $response = $this->getJson('/api/users?' . http_build_query([
            'fields'  => ['filterable_users' => 'name'],
            'filters' => json_encode(['email' => ['$like' => '@keep.com']]),
            'order'   => 'name:desc',
            'limit'   => 2,
        ]));

        $response->assertOk();

        // Limit applied: two of the four matching rows are returned.
        $response->assertJsonCount(2, 'data');

        // Sort applied: descending by name over the filtered set.
        $response->assertJsonPath('data.0.name', 'Delta');
        $response->assertJsonPath('data.1.name', 'Charlie');

        // Filter applied: the meta total reflects the four retained rows only,
        // and the two dropped-domain rows never reach the top of the sort.
        $response->assertJsonPath('meta.total', 4);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);

        // Fieldset applied: the requested key is present and the undeclared key
        // is absent.
        $record = $response->json('data.0');

        self::assertIsArray($record);
        self::assertArrayHasKey('name', $record);
        self::assertArrayNotHasKey('email', $record);
    }
}
