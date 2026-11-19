<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\FilterSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\ManualUser;
use Tests\Fixtures\Repositories\ManualUserRepository;
use Tests\Fixtures\Resources\ManualCommentResource;
use Tests\Fixtures\Resources\ManualOrganizationResource;
use Tests\Fixtures\Resources\ManualUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Round trips the exported filter document and order keys of a resource through
 * the endpoint serving it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterSchemaBuilder::class)]
#[CoversClass(QueryParameterBuilder::class)]
final class ExportedQueryParameterRoundTripTest extends TestCase
{
    use RegistersApiExceptionHandler, ValidatesOpenApiDocuments;

    /** @var string The pointer to the resource's exported filter document */
    private const string FILTERS = '/components/schemas/ManualUserFilters';

    /**
     * Serve the users index over a resource declaring columns, relations,
     * scopes, search and order keys.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [ManualUserResource::class, ManualOrganizationResource::class, ManualCommentResource::class, PostResource::class]);
        Config::set('api-toolkit.resources.resource_map', [ManualUser::class => ManualUserResource::class]);
        Config::set('api-toolkit.repositories.allow_random_order', true);

        if (DB::connection()->getDriverName() === 'sqlite') {
            Config::set('api-toolkit.search.unverified_connections', [DB::getDefaultConnection()]);
        }

        Route::middleware(ParseApiQuery::class)->get('/users', static fn (ManualUserRepository $users): ApiResourceCollection => new ApiResourceCollection(
            $users->withApiCriteria()->paginate(),
            ManualUserResource::class,
        ));
    }

    /**
     * Provide filter documents the exported schema accepts.
     *
     * @return iterable<string, array{string}>
     */
    public static function acceptedFilterProvider(): iterable
    {
        yield 'shorthand' => ['{"last_name":"Smith"}'];
        yield 'operator object' => ['{"age":{"$gt":18,"$le":65}}'];
        yield 'list' => ['{"status":{"$in":["active","pending"]}}'];
        yield 'bounds' => ['{"age":{"$between":[18,65]}}'];
        yield 'presence' => ['{"age":{"$null":true}}'];
        yield 'date-time' => ['{"created_at":{"$ge":"2024-01-01T00:00:00Z"}}'];
        yield 'group' => ['{"$or":[{"first_name":"John"},{"last_name":"Smith"}]}'];
        yield 'relation body' => ['{"posts":{"title":"Hello"}}'];
        yield 'relation names' => ['{"$has":["posts","comments"]}'];
        yield 'relation keyed to a body' => ['{"$hasnt":{"posts":{"title":"Hello"}}}'];
        yield 'scope by name' => ['{"$scope":"upcoming"}'];
        yield 'scope with arguments' => ['{"$scope":{"near":{"lat":51.5,"lng":-0.12,"km":5}}}'];
        yield 'scope in a group' => ['{"$and":[{"$scope":["upcoming"]},{"email":"a@example.com"}]}'];
    }

    /**
     * Test that a filter document the exported schema accepts is answered.
     *
     * @param  string  $filters
     * @return void
     */
    #[DataProvider('acceptedFilterProvider')]
    public function testAFilterTheSchemaAcceptsIsAnswered(string $filters): void
    {
        $result = $this->validateAgainstExported($this->exported(), self::FILTERS, json_decode($filters));

        self::assertTrue($result->isValid(), $this->formatErrors($result));

        $this->filtered($filters)->assertOk();
    }

    /**
     * Provide filter documents the exported schema rejects.
     *
     * @return iterable<string, array{string}>
     */
    public static function rejectedFilterProvider(): iterable
    {
        yield 'unknown key' => ['{"nickname":"Johnny"}'];
        yield 'unknown token' => ['{"age":{"$like":"1"}}'];
        yield 'one bound' => ['{"age":{"$between":[18]}}'];
        yield 'token the column does not answer' => ['{"last_name":{"$gt":"A"}}'];
        yield 'scope missing its arguments' => ['{"$scope":"near"}'];
        yield 'empty group' => ['{"$or":[]}'];
    }

    /**
     * Test that a filter document the exported schema rejects is refused.
     *
     * @param  string  $filters
     * @return void
     */
    #[DataProvider('rejectedFilterProvider')]
    public function testAFilterTheSchemaRejectsIsRefused(string $filters): void
    {
        self::assertFalse($this->validateAgainstExported($this->exported(), self::FILTERS, json_decode($filters))->isValid());

        $this->filtered($filters)->assertStatus(422);
    }

    /**
     * Test that every order key the index lists is answered under offset
     * pagination, relevance with a search term, and relevance only ascending.
     *
     * @return void
     */
    public function testEveryListedOrderKeyIsAnswered(): void
    {
        $keys = $this->orderKeys();

        self::assertContains('relevance:asc', $keys);
        self::assertNotContains('relevance:desc', $keys);

        foreach ($keys as $key) {

            $search = str_starts_with($key, 'relevance') ? '&search=John' : '';

            $this->getJson('/users?order=' . $key . $search)->assertOk();
        }
    }

    /**
     * Define the database migrations, adding the columns the resource reads.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->unsignedInteger('age')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->softDeletes();
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->boolean('flagged')->default(false);
        });
    }

    /**
     * Build the exported filter documents and the enums they reference.
     *
     * @return array<string, mixed>
     */
    private function exported(): array
    {
        assert($this->app !== null);

        $claimed = [];
        $filters = $this->app->make(FilterSchemaBuilder::class)->build($claimed);
        $enums   = $this->app->make(EnumSchemaBuilder::class)->build($this->app->make(EnumSchemaRegistry::class)->classes());

        return ['components' => ['schemas' => [...$filters, ...$enums]]];
    }

    /**
     * List the order keys the users index accepts.
     *
     * @return list<string>
     */
    private function orderKeys(): array
    {
        assert($this->app !== null);

        foreach ($this->app->make(QueryParameterBuilder::class)->referencesFor('index', ManualUser::class, ManualUserResource::class) as $parameter) {
            if (($parameter['name'] ?? null) === 'order') {
                /** @var list<string> */
                return $parameter['schema']['items']['enum'];
            }
        }

        self::fail('The users index lists no order.');
    }

    /**
     * Request the users index under the raw filter document.
     *
     * @param  string  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function filtered(string $filters): TestResponse
    {
        return $this->getJson('/users?filters=' . urlencode($filters));
    }
}
