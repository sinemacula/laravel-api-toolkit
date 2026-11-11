<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test proving a provider-registered custom operator applies over HTTP.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(OperatorRegistry::class)]
final class CustomOperatorHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a users route, register a custom operator and seed rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        app(OperatorRegistry::class)->override('$starts', static function (Builder $query, string $column, mixed $value): void {

            $term = is_scalar($value) ? (string) $value : '';

            $query->where($column, 'like', $term . '%');
        });

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Alan', 'email' => 'alan@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    }

    /**
     * Test that the registered custom operator narrows the result set by a name
     * prefix.
     *
     * @return void
     */
    public function testCustomOperatorNarrowsByNamePrefix(): void
    {
        $filters = json_encode(['name' => ['$starts' => 'Al']]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 2);

        $names = array_column((array) $response->json('data'), 'name');

        self::assertEqualsCanonicalizing(['Alice', 'Alan'], $names);
    }
}
