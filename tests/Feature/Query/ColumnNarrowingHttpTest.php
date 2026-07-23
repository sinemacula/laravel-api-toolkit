<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests proving column narrowing over the real HTTP pipeline.
 *
 * Drives the middleware plus repository pagination path so the narrowing
 * decision is observed on the wire: with the flag on, the base users SELECT is
 * a strict column subset that never leaks the password column, while the
 * rendered envelope stays byte-identical to the flag-off baseline; an opaque
 * unmapped field forces the query to fall back to the full column set while the
 * computed value still renders.
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

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
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
     * Test that a narrowed request emits a strict-subset base SELECT while the
     * rendered envelope stays byte-identical to the flag-off baseline.
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

        // Flag-off selects the whole table; flag-on selects a strict subset
        // that never leaks the password column.
        self::assertStringContainsString('users.*', $this->unquote($offSql));
        self::assertStringNotContainsString('users.*', $this->unquote($onSql));
        self::assertStringContainsString('name', $this->unquote($onSql));
        self::assertStringContainsString('email', $this->unquote($onSql));
        self::assertStringNotContainsString('password', $this->unquote($onSql));

        // The rendered envelope is unchanged by the SQL narrowing.
        self::assertSame($off->baseResponse->getContent(), $on->baseResponse->getContent());
    }

    /**
     * Test that an opaque unmapped field forces a fallback to the full SELECT
     * while the computed value still renders.
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

        // The un-annotated compute is opaque, so the narrower falls back to the
        // full column set rather than risk dropping a column the closure reads.
        self::assertStringContainsString('users.*', $this->unquote($sql));

        // The computed field still resolves from the fully-hydrated model.
        $response->assertJsonPath('data.0.full_label', 'Alice <alice@example.com>');
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
            $sql      = (string) $entry['query'];
            $unquoted = $this->unquote($sql);

            if (!str_starts_with($sql, 'select') || !str_contains($unquoted, 'from users')) {
                continue;
            }

            // Skip the paginator's row-count query and the schema introspection
            // statements so only the hydrating base select is returned.
            if (str_contains($unquoted, 'as aggregate') || str_contains($unquoted, 'pragma') || str_contains($unquoted, 'sqlite_master')) {
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
