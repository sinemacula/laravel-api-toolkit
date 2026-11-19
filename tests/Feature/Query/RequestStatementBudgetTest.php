<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ListingUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\QueryableTagResource;
use Tests\Fixtures\Resources\TraversingPostResource;
use Tests\TestCase;

/**
 * Statement budgets for a production-shaped index request on the shipped
 * defaults.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(SchemaIntrospector::class)]
final class RequestStatementBudgetTest extends TestCase
{
    use RecordsStatements;

    /** @var string The statements that read a schema catalogue on any engine */
    private const string CATALOGUE = '/pragma_|sqlite_master|information_schema|pg_catalog|pg_attribute|pg_index|pg_class/';

    /** @var array<string, string> The kind of read each statement pattern names */
    private const array KINDS = [
        '/order by (?:users\.)?name asc, (?:users\.)?email asc, (?:users\.)?id desc limit /' => 'page',
        '/^select count\(\*\) as aggregate from \(select 1 from users /'                     => 'count',
        '/^select (?:\*|[\w., ]+) from (\w+) where \1\.\w+ in /'                             => 'eager load',
        '/ over \(partition by [^)]+\) as laravel_row from (\w+) /'                          => 'window',
    ];

    /** @var array<int, string> The statements every warm read of the listing runs */
    private const array READ = ['page', 'eager load profiles', 'eager load organizations', 'window posts', 'window tags'];

    /** @var int The organization every seeded user belongs to */
    private int $organizationId;

    /** @var array<int, int> The tags every seeded post carries */
    private array $tagIds;

    /**
     * Set up the users listing route and the organization and tags the seeded
     * users share.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [ListingUserResource::class, OrganizationResource::class, QueryableTagResource::class, TraversingPostResource::class]);

        $connection = DB::connection();

        // SQLite cannot prove a search index, so it serves the search unproven
        if ($connection->getDriverName() === 'sqlite') {
            Config::set('api-toolkit.search.unverified_connections', [$connection->getName()]);
        }

        Route::middleware(ParseApiQuery::class)->get('/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(ListingUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, ListingUserResource::class);
        });

        $this->organizationId = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp'])->id;
        $this->tagIds         = [Tag::create(['name' => 'php'])->id, Tag::create(['name' => 'sql'])->id];
    }

    /**
     * Provide each pagination mode and page size with the statements its warm
     * read runs.
     *
     * @return iterable<string, array{0: ?string, 1: int, 2: array<int, string>}>
     */
    public static function readProvider(): iterable
    {
        yield 'offset, partial page' => [null, 100, self::READ];
        yield 'offset, full page' => [null, 5, [...self::READ, 'count']];
        yield 'cursor' => ['cursor', 100, self::READ];
    }

    /**
     * Test that a warm read runs one statement per relation path, and a count
     * only for a full offset page, whether it reads five users or fifty.
     *
     * @param  string|null  $pagination
     * @param  int  $limit
     * @param  array<int, string>  $expected
     * @return void
     */
    #[DataProvider('readProvider')]
    public function testWarmReadRunsTheSameStatementsForFiftyUsersAsForFive(?string $pagination, int $limit, array $expected): void
    {
        $uri = $this->listingUri(['pagination' => $pagination, 'limit' => $limit]);

        $this->seedUsers(1, 5);
        $this->getJson($uri)->assertOk();

        [$small] = $this->read($uri);

        $this->seedUsers(6, 50);

        [$large, $response] = $this->read($uri);

        self::assertSame($expected, array_map(self::classify(...), $small), implode("\n", $small));
        self::assertSame($expected, array_map(self::classify(...), $large), implode("\n", $large));

        $response->assertJsonCount(min($limit, 50), 'data');
        $response->assertJsonPath('data.0.organization.name', 'Acme Corp');
        $response->assertJsonPath('data.0.profile_bio', 'Bio 1');
        $response->assertJsonPath('data.0.posts.0.tags.*.name', ['php', 'sql']);
        $response->assertJsonPath('data.0.aggregates.posts_count', 1);
    }

    /**
     * Test that a cold read asks each catalogue question once, and that the
     * next read asks none and writes no metadata.
     *
     * A cursor read wants the column names, to weigh narrowing its select, and
     * their definitions, to refuse a nullable sort key.
     *
     * @return void
     */
    public function testColdReadAsksEachCatalogueQuestionOnceAndTheNextNone(): void
    {
        $uri = $this->listingUri(['pagination' => 'cursor', 'limit' => 100]);

        $this->seedUsers(1, 5);

        $this->app?->make(CacheManager::class)->invalidateMetadata();

        $written = [];

        Event::listen(KeyWritten::class, static function (KeyWritten $event) use (&$written): void {
            $written[] = $event->key;
        });

        [$cold] = $this->read($uri);

        $coldWrites = $written;
        $written    = [];

        [$warm] = $this->read($uri);

        $catalogue = self::catalogueReads($cold);

        self::assertNotSame([], $catalogue);
        self::assertSame(array_values(array_unique($catalogue)), $catalogue, implode("\n", $catalogue));
        self::assertNotSame([], $coldWrites);
        self::assertSame([], self::catalogueReads($warm));
        self::assertSame([], $written);
    }

    /**
     * Test that the select computes the aggregates only when the response
     * renders them, and then exactly the defaults.
     *
     * @return void
     */
    public function testSelectComputesOnlyTheAggregatesTheResponseRenders(): void
    {
        $this->seedUsers(1, 5);

        $withoutUri = '/users?' . http_build_query(['fields' => [ListingUserResource::RESOURCE_TYPE => 'name']]);
        $withUri    = '/users?' . http_build_query(['fields' => [ListingUserResource::RESOURCE_TYPE => 'name,aggregates']]);

        $this->getJson($withoutUri)->assertOk();

        [$without, $withoutResponse] = $this->read($withoutUri);
        [$with, $withResponse]       = $this->read($withUri);

        self::assertStatementCount(1, $without);
        self::assertStatementCount(1, $with);
        self::assertStringNotContainsString('aggregate_', $without[0]);
        self::assertSame(['aggregate_posts_count', 'aggregate_posts_sum_id'], self::aggregateAliases($with[0]));

        $withoutResponse->assertJsonMissingPath('data.0.aggregates');
        $withResponse->assertJsonPath('data.0.aggregates.posts_count', 1);

        self::assertSame(['posts_count', 'posts_sum_id'], array_keys((array) $withResponse->json('data.0.aggregates')));
    }

    /**
     * Test that narrowing the select adds no statement to a warm read.
     *
     * @return void
     */
    public function testNarrowingAddsNoStatementToAWarmRead(): void
    {
        $uri = $this->listingUri(['fields' => [ListingUserResource::RESOURCE_TYPE => 'name,email']]);

        $this->seedUsers(1, 5);
        $this->getJson($uri)->assertOk();

        [$narrowed, $narrowedResponse] = $this->read($uri);

        Config::set('api-toolkit.resources.narrow_columns', false);

        [$full, $fullResponse] = $this->read($uri);

        self::assertStatementCount(1, $narrowed);
        self::assertStatementCount(1, $full);
        self::assertStringNotContainsString('*', self::selectList($narrowed[0]));
        self::assertStringContainsString('*', self::selectList($full[0]));
        self::assertSame($fullResponse->json(), $narrowedResponse->json());
    }

    /**
     * Name the kind of read a statement makes, or return it whole when it is
     * none the listing should run.
     *
     * @param  string  $sql
     * @return string
     *
     * @SuppressWarnings("php:S1144")
     */
    private static function classify(string $sql): string
    {
        $sql = self::unquote($sql);

        foreach (self::KINDS as $pattern => $kind) {
            if (preg_match($pattern, $sql, $match) === 1) {
                return isset($match[1]) ? $kind . ' ' . $match[1] : $kind;
            }
        }

        return $sql;
    }

    /**
     * Return the statements that read a schema catalogue.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private static function catalogueReads(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => preg_match(self::CATALOGUE, $sql) === 1));
    }

    /**
     * Return the aggregate aliases a select computes.
     *
     * @param  string  $sql
     * @return array<int, string>
     */
    private static function aggregateAliases(string $sql): array
    {
        preg_match_all('/ as (aggregate_\w+)/', self::unquote($sql), $matches);

        return $matches[1];
    }

    /**
     * Return the columns a select lists, before its first table.
     *
     * @param  string  $sql
     * @return string
     */
    private static function selectList(string $sql): string
    {
        return explode(' from ', self::unquote($sql), 2)[0];
    }

    /**
     * Strip the identifier quoting each engine applies.
     *
     * @param  string  $sql
     * @return string
     */
    private static function unquote(string $sql): string
    {
        return str_replace(['"', '`'], '', $sql);
    }

    /**
     * Build the listing request, the given parameters replacing its own.
     *
     * It renders every relation path, filters across two relations, searches
     * and sorts on three keys.
     *
     * @param  array<string, mixed>  $parameters
     * @return string
     */
    private function listingUri(array $parameters): string
    {
        return '/users?' . http_build_query([
            'fields' => [
                ListingUserResource::RESOURCE_TYPE    => 'name,organization,profile_bio,posts,aggregates',
                TraversingPostResource::RESOURCE_TYPE => 'title,tags',
                QueryableTagResource::RESOURCE_TYPE   => 'name',
            ],
            'filters' => json_encode(['$has' => 'organization', 'posts' => ['tags' => ['name' => 'php']]]),
            'search'  => 'Ada',
            'order'   => 'name:asc,email:asc,id:desc',
            ...$parameters,
        ]);
    }

    /**
     * Request the given URI as a new operation and return the statements it ran
     * with the response.
     *
     * The per-operation state is reset first, as a worker resets it between
     * requests, so only the shared store can serve warm metadata.
     *
     * @param  string  $uri
     * @return array{0: list<string>, 1: \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>}
     */
    private function read(string $uri): array
    {
        $this->app?->make(CacheManager::class)->flush();

        $response = null;

        $statements = $this->recordStatements(function () use ($uri, &$response): void {
            $response = $this->getJson($uri);
        });

        assert($response instanceof TestResponse);

        $response->assertOk();

        return [$statements, $response];
    }

    /**
     * Seed the numbered users, each with a profile and one tagged post.
     *
     * @param  int  $from
     * @param  int  $to
     * @return void
     */
    private function seedUsers(int $from, int $to): void
    {
        for ($number = $from; $number <= $to; $number++) {

            $user = User::create(['name' => 'Ada', 'email' => sprintf('ada%02d@example.com', $number), 'organization_id' => $this->organizationId]);

            Profile::create(['user_id' => $user->id, 'bio' => 'Bio ' . $number]);

            Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number, 'body' => 'Body'])->tags()->attach($this->tagIds);
        }
    }
}
