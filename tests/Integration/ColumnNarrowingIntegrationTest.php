<?php

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end integration tests for repository-driven column narrowing.
 *
 * Drives the full ApiCriteria applier chain against a real SQLite database,
 * capturing the emitted base-table SQL via the query log to prove the
 * narrowing decision, the safety set, and byte-identical default-off behaviour.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjectionApplier::class)]
#[CoversClass(ApiCriteria::class)]
class ColumnNarrowingIntegrationTest extends TestCase
{
    private const string TEST_URL = '/test';

    /**
     * Set up each test with a clean schema/map cache and seeded data.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        // Pin the blocklist posture so ordering follows the legacy isSearchable
        // contract; this test asserts column-narrowing mechanics, not the
        // allowlist posture (covered in QuerySurfaceIntegrationTest).
        Config::set('api-toolkit.repositories.query_posture', QuerySurface::POSTURE_BLOCKLIST);

        $this->seedData();
    }

    /**
     * Tear down each test, clearing the static caches.
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
     * With the flag off the base query selects every column and the response is
     * byte-identical to the baseline across the field taxonomy.
     *
     * @return void
     */
    public function testFlagOffEmitsSelectStarAndByteIdenticalResponses(): void
    {
        $fields = 'name,email,status,full_label,display_label,organization';

        Config::set('api-toolkit.resources.narrow_columns', false);

        $baseline = $this->serialiseUsers($fields);
        $columns  = $this->captureUserColumns($fields);
        $sql      = $this->captureUserSql($fields, 'users');

        static::assertSame(['*'], $columns);
        static::assertStringContainsString('users.*', $this->unquote($sql));
        static::assertSame($baseline, $this->serialiseUsers($fields));
    }

    /**
     * With the flag on and a fully-mapped field set, the base query selects a
     * strict subset of the table columns and the response is byte-identical.
     *
     * @return void
     */
    public function testNarrowsForScalarAndDeclaredFields(): void
    {
        $off = $this->serialiseUsers('name,email,status,display_label');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureUserColumns('name,email,status,display_label');

        static::assertNotContains('*', $columns);
        static::assertContains('name', $columns);
        static::assertContains('email', $columns);
        static::assertLessThan(count($this->userColumns()), count($columns));
        static::assertSame($off, $this->serialiseUsers('name,email,status,display_label'));
    }

    /**
     * With the flag on and an un-annotated opaque field present, the narrower
     * falls back and the base query selects every column.
     *
     * @return void
     */
    public function testFallsBackForUnannotatedOpaqueField(): void
    {
        $off = $this->serialiseUsers('name,email,full_label');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureUserColumns('name,email,full_label');

        static::assertSame(['*'], $columns);
        static::assertSame($off, $this->serialiseUsers('name,email,full_label'));
    }

    /**
     * With the flag on, the safety set retains the primary key, soft-delete
     * column, append source, and order column so every scalar/accessor field
     * resolves and the response is byte-identical; a requested relation field
     * is opaque, so the query safely falls back and the relation still hydrates.
     *
     * @return void
     */
    public function testSafetySetKeepsRelationSoftDeleteAliasAndOrder(): void
    {
        $scalarOff   = $this->serialiseArticles('title,slug,summary_excerpt', 'views:desc');
        $relationOff = $this->serialiseArticles('title,author', 'views:desc');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureArticleColumns('title,slug,summary_excerpt', 'views:desc');

        static::assertNotContains('*', $columns);
        static::assertContains('id', $columns);
        static::assertContains('deleted_at', $columns);
        static::assertContains('views', $columns);
        static::assertContains('summary', $columns);
        static::assertSame($scalarOff, $this->serialiseArticles('title,slug,summary_excerpt', 'views:desc'));

        static::assertSame(['*'], $this->captureArticleColumns('title,author', 'views:desc'));
        static::assertSame($relationOff, $this->serialiseArticles('title,author', 'views:desc'));
    }

    /**
     * With the flag on, a column-mapped field that pulls a `belongsTo` via
     * `extras` retains the relation's parent foreign key in the narrowed
     * select, so the eager-loaded relation still hydrates instead of silently
     * resolving to null.
     *
     * @return void
     */
    public function testSafetySetRetainsParentKeyForExtrasEagerLoadedBelongsTo(): void
    {
        $off = $this->serialiseArticles('id,author_name', null);

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureArticleColumns('id,author_name', null);

        static::assertNotContains('*', $columns);
        static::assertContains('user_id', $columns);
        static::assertSame($off, $this->serialiseArticles('id,author_name', null));
    }

    /**
     * A field set changed imperatively after the query executes cannot reach the
     * built query, so an opaque field set leaves the executed query selecting all
     * columns while the late field set still renders correctly.
     *
     * @return void
     */
    public function testLateOverrideUsesSelectStar(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseUserQuery('name,email,full_label');

        $query = $this->applyUserCriteria();

        DB::enableQueryLog();

        $user = $query->first();

        $sql = $this->lastSelectSql('users');

        static::assertNotNull($user);
        static::assertSame(['*'], $this->normaliseColumns($query->getQuery()->columns));
        static::assertStringContainsString('users.*', $this->unquote($sql));

        $resource = (new UserResource($user))->withFields(['status']);
        $data     = $resource->resolve(Request::create(self::TEST_URL));

        static::assertArrayHasKey('status', $data);
    }

    /**
     * A narrowed select survives pagination because the hard-coded paginate('*')
     * only applies when no columns have been set on the query.
     *
     * @return void
     */
    public function testNarrowedSelectSurvivesPaginate(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseUserQuery('name,email');

        DB::enableQueryLog();

        $this->applyUserCriteria()->paginate(15, '*');

        $sql = $this->lastSelectSql();

        static::assertStringContainsString('name', $this->unquote($sql));
        static::assertStringNotContainsString('select *', $sql);
    }

    /**
     * Narrowing reduces both the base-query column count and the hydrated model's
     * fetched attribute payload while keeping the serialised API response identical.
     *
     * The response is byte-identical by design (narrowing changes the SQL, not
     * the output). The genuine data-layer reduction is proven by comparing the
     * serialised attribute map of a model fetched with a narrowed SELECT (which
     * omits heavy columns like `body` from the result set) against a model fetched
     * with a full SELECT – the narrowed model's attributes must weigh less.
     *
     * @return void
     */
    public function testNarrowingReducesColumnsAndBytes(): void
    {
        $offResponse = $this->serialiseArticles('title,slug,status', null);
        $offColumns  = $this->articleColumns();
        $fullModel   = $this->fetchFirstArticle();
        $fullBytes   = strlen(serialize($fullModel->getAttributes()));

        Config::set('api-toolkit.resources.narrow_columns', true);

        $onColumns     = $this->captureArticleColumns('title,slug,status', null);
        $narrowedModel = $this->fetchFirstArticle();
        $narrowedBytes = strlen(serialize($narrowedModel->getAttributes()));
        $onResponse    = $this->serialiseArticles('title,slug,status', null);

        static::assertNotContains('*', $onColumns);
        static::assertLessThan(count($offColumns), count($onColumns));
        static::assertLessThan($fullBytes, $narrowedBytes);
        static::assertSame($offResponse, $onResponse);
    }

    /**
     * A scalar-only resource narrows with zero annotation and reuses its compiled
     * field-column map across requests of the same type.
     *
     * @return void
     */
    public function testScalarOnlyResourceNarrowsWithoutAnnotationAndReusesMap(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureArticleColumns('title,slug', null);

        static::assertNotContains('*', $columns);
        static::assertContains('title', $columns);

        $first  = FieldColumnMapper::for(ArticleResource::class);
        $second = FieldColumnMapper::for(ArticleResource::class);

        static::assertSame($first, $second);
    }

    /**
     * Serialise the seeded users for the given user field set.
     *
     * @param  string  $fields
     * @return string
     */
    private function serialiseUsers(string $fields): string
    {
        $this->parseUserQuery($fields);

        $users = $this->applyUserCriteria()->get();

        return $this->encode(UserResource::collection($users)->resolve(Request::create(self::TEST_URL)));
    }

    /**
     * Capture the SQL emitted against the given table when fetching users.
     *
     * @param  string  $fields
     * @param  string  $table
     * @return string
     */
    private function captureUserSql(string $fields, string $table): string
    {
        $this->parseUserQuery($fields);

        DB::enableQueryLog();

        $this->applyUserCriteria()->get();

        return $this->lastSelectSql($table);
    }

    /**
     * Capture the normalised base-table column names set on the user query.
     *
     * @param  string  $fields
     * @return array<int, string>
     */
    private function captureUserColumns(string $fields): array
    {
        $this->parseUserQuery($fields);

        return $this->normaliseColumns($this->applyUserCriteria()->getQuery()->columns);
    }

    /**
     * Serialise the seeded articles for the given article field set and order.
     *
     * @param  string  $fields
     * @param  string|null  $order
     * @return string
     */
    private function serialiseArticles(string $fields, ?string $order): string
    {
        $this->parseArticleQuery($fields, $order);

        $articles = $this->applyArticleCriteria()->get();

        return $this->encode(ArticleResource::collection($articles)->resolve(Request::create(self::TEST_URL)));
    }

    /**
     * Capture the normalised base-table column names set on the article query.
     *
     * @param  string  $fields
     * @param  string|null  $order
     * @return array<int, string>
     */
    private function captureArticleColumns(string $fields, ?string $order): array
    {
        $this->parseArticleQuery($fields, $order);

        return $this->normaliseColumns($this->applyArticleCriteria()->getQuery()->columns);
    }

    /**
     * Parse a user request for the given field set.
     *
     * @param  string  $fields
     * @return void
     */
    private function parseUserQuery(string $fields): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => $fields],
        ]);

        ApiQuery::parse($request);
    }

    /**
     * Parse an article request for the given field set and optional order.
     *
     * @param  string  $fields
     * @param  string|null  $order
     * @return void
     */
    private function parseArticleQuery(string $fields, ?string $order): void
    {
        $params = ['fields' => ['articles' => $fields]];

        if ($order !== null) {
            $params['order'] = $order;
        }

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $params);

        ApiQuery::parse($request);
    }

    /**
     * Apply the criteria chain to a user query bound to the user resource.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function applyUserCriteria(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User> */
        return $this->makeCriteria()->usingResource(UserResource::class)->apply(new User);
    }

    /**
     * Apply the criteria chain to an article query bound to the article resource.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Article>
     */
    private function applyArticleCriteria(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Article> */
        return $this->makeCriteria()->usingResource(ArticleResource::class)->apply(new Article);
    }

    /**
     * Resolve a fresh ApiCriteria instance from the container.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function makeCriteria(): ApiCriteria
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria */
        return $this->app->make(ApiCriteria::class);
    }

    /**
     * Get the SQL of the most recent select statement against the given table.
     *
     * @param  string  $table
     * @return string
     */
    private function lastSelectSql(string $table = ''): string
    {
        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach (array_reverse($log) as $entry) {
            $query = (string) $entry['query'];

            if (str_starts_with($query, 'select') && ($table === '' || str_contains($this->unquote($query), 'from ' . $table))) {
                return $query;
            }
        }

        return '';
    }

    /**
     * Strip identifier quote characters so SQL assertions are driver-agnostic:
     * SQLite and PostgreSQL double-quote identifiers, MySQL backticks them.
     *
     * @param  string  $sql
     * @return string
     */
    private function unquote(string $sql): string
    {
        return str_replace(['`', '"'], '', $sql);
    }

    /**
     * Get the real column listing for the users table.
     *
     * @return array<int, string>
     */
    private function userColumns(): array
    {
        return ['id', 'organization_id', 'name', 'email', 'password', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the real column listing for the articles table.
     *
     * @return array<int, string>
     */
    private function articleColumns(): array
    {
        return [
            'id', 'user_id', 'title', 'slug', 'body', 'summary',
            'status', 'views', 'created_at', 'updated_at', 'deleted_at',
        ];
    }

    /**
     * Fetch the first article row under the current narrowing config for
     * attribute-payload comparison.
     *
     * The caller is responsible for setting the narrow_columns config flag before
     * invoking this helper; applyArticleCriteria() honours whatever is currently
     * configured.
     *
     * @return \Tests\Fixtures\Models\Article
     */
    private function fetchFirstArticle(): Article
    {
        $this->parseArticleQuery('title,slug,status', null);

        /** @var \Tests\Fixtures\Models\Article */
        return $this->applyArticleCriteria()->firstOrFail();
    }

    /**
     * Encode a value as deterministic JSON for byte-level comparison.
     *
     * @param  mixed  $value
     * @return string
     */
    private function encode(mixed $value): string
    {
        return (string) json_encode($value);
    }

    /**
     * Normalise a builder column list to plain base-table column names.
     *
     * A null column list (plain `select *`) and a withCount-injected
     * `['table.*', Expression]` both collapse to the wildcard marker, while a
     * narrowed list returns its string column names.
     *
     * @param  array<int, mixed>|null  $columns
     * @return array<int, string>
     */
    private function normaliseColumns(?array $columns): array
    {
        if ($columns === null) {
            return ['*'];
        }

        $names = [];

        foreach ($columns as $column) {
            if (!is_string($column)) {
                continue;
            }

            $names[] = str_contains($column, '.*') ? '*' : $column;
        }

        return $names === [] ? ['*'] : array_values(array_unique($names));
    }

    /**
     * Seed users, organizations, posts, and articles for the suite.
     *
     * @return void
     */
    private function seedData(): void
    {
        $org = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $alice = User::create([
            'name'            => 'Alice',
            'email'           => 'alice@example.com',
            'status'          => 'active',
            'organization_id' => $org->id,
        ]);

        $bob = User::create([
            'name'            => 'Bob',
            'email'           => 'bob@example.com',
            'status'          => 'active',
            'organization_id' => $org->id,
        ]);

        Post::create(['user_id' => $alice->id, 'title' => 'First Post', 'body' => 'Content', 'published' => true]);

        $this->seedArticles($alice->id, $bob->id);
    }

    /**
     * Seed the wide, soft-deleting articles for the two authors.
     *
     * @param  int  $aliceId
     * @param  int  $bobId
     * @return void
     */
    private function seedArticles(int $aliceId, int $bobId): void
    {
        Article::create([
            'user_id' => $aliceId,
            'title'   => 'Wide Article',
            'slug'    => 'wide-article',
            'body'    => str_repeat('lorem ipsum dolor ', 20),
            'summary' => 'A concise summary of the wide article body content.',
            'status'  => 'published',
            'views'   => 128,
        ]);

        Article::create([
            'user_id' => $bobId,
            'title'   => 'Second Article',
            'slug'    => 'second-article',
            'body'    => str_repeat('consectetur adipiscing ', 20),
            'summary' => 'Another summary describing the second article in brief.',
            'status'  => 'draft',
            'views'   => 64,
        ]);
    }
}
