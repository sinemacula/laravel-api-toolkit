<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\WalksPaginationLinks;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests pinning where each engine's own comparison rules show through a
 * filter or a sort.
 *
 * Equality and order follow the column's collation, and NULLs sort where the
 * engine puts them; the toolkit normalises neither.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(OrderApplier::class)]
final class EngineSemanticsHttpTest extends TestCase
{
    use RegistersApiExceptionHandler, WalksPaginationLinks;

    /**
     * Set up a users route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [FilterableUserResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });
    }

    /**
     * Test that equality differing only in case matches under a
     * case-insensitive collation only.
     *
     * @return void
     */
    public function testEqualityDifferingInCaseFollowsTheCollation(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $response = $this->getJson('/users?filters=' . urlencode((string) json_encode(['name' => 'alice'])));

        $response->assertOk();

        self::assertSame($this->engine(['mysql' => ['Alice'], 'pgsql' => [], 'sqlite' => []]), array_column((array) $response->json('data'), 'name'));
    }

    /**
     * Test that a mixed-case sort follows the collation.
     *
     * @return void
     */
    public function testMixedCaseSortFollowsTheCollation(): void
    {
        foreach (['cara', 'Ben', 'anna'] as $name) {
            User::create(['name' => $name, 'email' => strtolower($name) . '@example.com']);
        }

        $response = $this->getJson('/users?order=name');

        $response->assertOk();

        self::assertSame(
            $this->engine(['mysql' => ['anna', 'Ben', 'cara'], 'pgsql' => ['anna', 'Ben', 'cara'], 'sqlite' => ['Ben', 'anna', 'cara']]),
            array_column((array) $response->json('data'), 'name'),
        );
    }

    /**
     * Provide each direction with where every engine places NULLs in it.
     *
     * @return iterable<string, array{string, array<string, array<int, string>>}>
     */
    public static function nullPlacementProvider(): iterable
    {
        $first = ['n1', 'n2', 'early', 'late'];
        $last  = ['early', 'late', 'n1', 'n2'];

        yield 'ascending' => ['asc', ['mysql' => $first, 'pgsql' => $last, 'sqlite' => $first]];
        yield 'descending' => ['desc', ['mysql' => array_reverse($first), 'pgsql' => array_reverse($last), 'sqlite' => array_reverse($first)]];
    }

    /**
     * Test that offset pages over a nullable sort place NULLs where the engine
     * does, each row once.
     *
     * @param  string  $direction
     * @param  array<string, array<int, string>>  $expected
     * @return void
     */
    #[DataProvider('nullPlacementProvider')]
    public function testOffsetPagesPlaceNullsWhereTheEngineDoes(string $direction, array $expected): void
    {
        foreach (['late' => '2026-01-02 00:00:00', 'n1' => null, 'early' => '2026-01-01 00:00:00', 'n2' => null] as $name => $created) {
            User::create(['name' => $name, 'email' => $name . '@example.com'])->forceFill(['created_at' => $created])->saveQuietly();
        }

        $pages = $this->walk($this->getJson('/users?order=created_at:' . $direction . '&limit=3'), 'name');

        self::assertSame(array_chunk($this->engine($expected), 3), $pages);
    }

    /**
     * Pick the expectation for the connection's engine.
     *
     * @param  array<string, array<int, string>>  $expectations
     * @return array<int, string>
     */
    private function engine(array $expectations): array
    {
        return $expectations[DB::connection()->getDriverName()];
    }
}
