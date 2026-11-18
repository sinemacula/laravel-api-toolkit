<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for a collection of models the controller did not preload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResourceCollection::class)]
final class CollectionLoadMissingQueryBoundTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /** @var string The field selection every request makes */
    private const string FIELDS = 'name,posts,organization,aggregates';

    /** @var int The organization every seeded user belongs to */
    private int $organizationId;

    /**
     * Set up a route per container a controller may collect, and seed five
     * users.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [OrganizationResource::class, PostResource::class, UserResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->group(static function (): void {
            Route::get('/collection', static fn (): AnonymousResourceCollection => UserResource::collection(User::query()->orderBy('id')->get()));
            Route::get('/paginator', static fn (): AnonymousResourceCollection => UserResource::collection(User::query()->orderBy('id')->paginate(10)));
            Route::get('/cursor', static fn (): AnonymousResourceCollection => UserResource::collection(User::query()->orderBy('id')->cursorPaginate(10)));
        });

        $this->organizationId = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp'])->id;

        $this->seedUsers(1, 5);
    }

    /**
     * Provide each route, whether its response is paginated and the statements
     * its read runs.
     *
     * @return iterable<string, array{string, bool, int}>
     */
    public static function routeProvider(): iterable
    {
        yield 'collection' => ['/collection', false, 1];
        yield 'length-aware paginator' => ['/paginator', true, 2];
        yield 'cursor paginator' => ['/cursor', true, 1];
    }

    /**
     * Test that every item renders the same keys and that serialising the items
     * runs no statement, whatever the row count.
     *
     * @param  string  $uri
     * @param  bool  $paginated
     * @param  int  $statements
     * @return void
     */
    #[DataProvider('routeProvider')]
    public function testEveryItemRendersTheSameKeysWithoutLoadingAnything(string $uri, bool $paginated, int $statements): void
    {
        $uri .= '?' . http_build_query(['fields' => ['users' => self::FIELDS]]);

        $small = $this->recordStatements(fn () => $this->getJson($uri)->assertOk());

        $this->seedUsers(6, 50);

        $response = null;
        $large    = $this->recordStatements(function () use ($uri, &$response): void {
            $response = $this->getJson($uri)->assertOk();
        });

        assert($response !== null);

        /** @var list<array<string, mixed>> $items */
        $items = $response->json('data');

        self::assertStatementCount($statements, $small);
        self::assertStatementCount($statements, $large);
        self::assertSame(array_fill(0, count($items), ['_type', 'id', 'name']), array_map(array_keys(...), $items));

        if (!$paginated) {
            self::assertCount(50, $items);

            return;
        }

        self::assertCount(10, $items);
        self::assertTrue($response->json('meta.continue'));
        self::assertNotNull($response->json('links.next'));
    }

    /**
     * Seed users numbered from the first to the last, each with two posts.
     *
     * @param  int  $first
     * @param  int  $last
     * @return void
     */
    private function seedUsers(int $first, int $last): void
    {
        for ($number = $first; $number <= $last; $number++) {

            $user = User::create([
                'name'            => 'User ' . $number,
                'email'           => 'user' . $number . '@example.com',
                'organization_id' => $this->organizationId,
            ]);

            Post::create(['user_id' => $user->id, 'title' => 'First', 'body' => 'Content']);
            Post::create(['user_id' => $user->id, 'title' => 'Second', 'body' => 'Content']);
        }
    }
}
