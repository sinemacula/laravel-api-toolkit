<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\AggregatingUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for column narrowing over the real HTTP pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjectionApplier::class)]
#[CoversClass(ColumnNarrower::class)]
#[CoversClass(ApiCriteria::class)]
#[CoversClass(ApiResourceCollection::class)]
final class ColumnNarrowingHttpTest extends TestCase
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

        Config::set(ResourceDiscovery::RESOURCES_KEY, [AggregatingUserResource::class, UserResource::class]);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/aggregating-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(AggregatingUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, AggregatingUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret', 'status' => 'active']);
    }

    /**
     * Tear down each test, clearing the static schema and map caches.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that a narrowed request emits a strict-subset select with a
     * byte-identical envelope.
     *
     * @return void
     */
    public function testNarrowedRequestEmitsSubsetSelectWithByteIdenticalEnvelope(): void
    {
        $query = http_build_query(['fields' => ['users' => 'name,email,status,display_label']]);

        Config::set('api-toolkit.resources.narrow_columns', false);

        DB::enableQueryLog();
        $off = $this->getJson('/users?' . $query);
        $off->assertOk();
        $offSql = $this->baseUsersSelect();

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        Config::set('api-toolkit.resources.narrow_columns', true);

        DB::enableQueryLog();
        $on = $this->getJson('/users?' . $query);
        $on->assertOk();
        $onSql = $this->baseUsersSelect();

        self::assertStringStartsWith('select * from users', $this->unquote($offSql));
        self::assertStringNotContainsString('*', $this->unquote($onSql));
        self::assertStringContainsString('name', $this->unquote($onSql));
        self::assertStringContainsString('email', $this->unquote($onSql));
        self::assertStringNotContainsString('password', $this->unquote($onSql));

        self::assertSame($off->baseResponse->getContent(), $on->baseResponse->getContent());
    }

    /**
     * Test that an opaque unmapped field falls back to the full select while
     * still rendering.
     *
     * @return void
     */
    public function testOpaqueFieldFallsBackToFullSelect(): void
    {
        $query = http_build_query(['fields' => ['users' => 'name,email,full_label']]);

        Config::set('api-toolkit.resources.narrow_columns', true);

        DB::enableQueryLog();
        $response = $this->getJson('/users?' . $query);
        $sql      = $this->baseUsersSelect();

        $response->assertOk();

        // The un-annotated compute is opaque, so its columns are unknown
        self::assertStringStartsWith('select * from users', $this->unquote($sql));

        $response->assertJsonPath('data.0.full_label', 'Alice <alice@example.com>');
    }

    /**
     * Test that a configured fixed field keeps its column in a narrowed select,
     * so the response still carries it.
     *
     * @return void
     */
    public function testNarrowedSelectKeepsAConfiguredFixedField(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);
        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type', 'status']);

        DB::enableQueryLog();
        $response = $this->getJson('/users?' . http_build_query(['fields' => ['users' => 'name']]));
        $sql      = $this->unquote($this->baseUsersSelect());

        $response->assertOk();

        self::assertStringNotContainsString('*', $sql);
        self::assertStringContainsString('status', $sql);
        self::assertSame(['_type' => 'users', 'id' => 1, 'name' => 'Alice', 'status' => 'active'], $response->json('data.0'));
    }

    /**
     * Test that ':all' keeps the aggregates, default or requested, when the
     * select is narrowed.
     *
     * @return void
     */
    public function testAllTokenKeepsTheAggregatesWhenNarrowed(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        Post::create(['user_id' => 1, 'title' => 'First', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => 1, 'title' => 'Second', 'body' => 'Content']);

        DB::enableQueryLog();
        $defaults    = $this->getJson('/aggregating-users?' . http_build_query(['fields' => ['aggregating_users' => ':all']]));
        $defaultsSql = $this->unquote($this->baseUsersSelect());

        DB::enableQueryLog();
        $requested = $this->getJson('/aggregating-users?' . http_build_query([
            'fields'     => ['aggregating_users' => ':all'],
            'aggregates' => ['aggregating_users' => 'posts_count'],
        ]));
        $requestedSql = $this->unquote($this->baseUsersSelect());

        $defaults->assertOk();
        $defaults->assertJsonPath('data.0.aggregates.posts_count', 2);
        $defaults->assertJsonPath('data.0.aggregates.has_published_posts', true);
        $defaults->assertJsonPath('data.1.aggregates', ['posts_count' => 0, 'has_published_posts' => false]);

        $requested->assertOk();
        $requested->assertJsonPath('data.0.aggregates', ['posts_count' => 2]);

        self::assertStringStartsWith('select users.id, users.name, (select count(*) from posts', $defaultsSql);
        self::assertStringContainsString('as aggregate_has_published_posts', $defaultsSql);
        self::assertStringStartsWith('select users.id, users.name, (select count(*) from posts', $requestedSql);
        self::assertStringNotContainsString('aggregate_has_published_posts', $requestedSql);
    }

    /**
     * Get the base users SELECT statement from the recorded query log.
     *
     * @return string
     */
    private function baseUsersSelect(): string
    {
        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach ($log as $entry) {
            $sql      = $entry['query'];
            $unquoted = $this->unquote($sql);

            if (!str_starts_with($sql, 'select') || !str_contains($unquoted, 'from users')) {
                continue;
            }

            // Skip the row-count query and schema introspection statements
            if (str_contains($unquoted, 'as aggregate from') || str_contains($unquoted, 'pragma') || str_contains($unquoted, 'sqlite_master')) {
                continue;
            }

            return $sql;
        }

        return '';
    }

    /**
     * Strip identifier quote characters so SQL assertions are driver-agnostic.
     *
     * @param  string  $sql
     * @return string
     */
    private function unquote(string $sql): string
    {
        return str_replace(['`', '"'], '', $sql);
    }
}
