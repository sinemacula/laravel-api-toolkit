<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\SluggedTitleArticle;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AggregatingUserResource;
use Tests\Fixtures\Resources\AliasedScalarUserResource;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\DeclaredSluggedTitleArticleResource;
use Tests\Fixtures\Resources\SluggedTitleArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end integration tests for repository-driven column narrowing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjectionApplier::class)]
#[CoversClass(ApiCriteria::class)]
final class ColumnNarrowingIntegrationTest extends TestCase
{
    /** @var string The route URI used to exercise the test endpoint. */
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

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            AggregatingUserResource::class,
            AliasedScalarUserResource::class,
            ArticleResource::class,
            DeclaredSluggedTitleArticleResource::class,
            SluggedTitleArticleResource::class,
            UserResource::class,
        ]);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

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
     * Test that with narrowing off the query selects * and output is unchanged.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFlagOffEmitsSelectStarAndByteIdenticalResponses(): void
    {
        $fields = 'name,email,status,full_label,display_label,organization';

        Config::set('api-toolkit.resources.narrow_columns', false);

        $baseline = $this->serialiseUsers($fields);
        $columns  = $this->captureUserColumns($fields);
        $sql      = $this->captureUserSql($fields, 'users');

        self::assertSame(['*'], $columns);
        self::assertStringStartsWith('select * from users', $this->unquote($sql));
        self::assertSame($baseline, $this->serialiseUsers($fields));
    }

    /**
     * Test that a fully-mapped field set narrows to a strict column subset.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNarrowsForScalarAndDeclaredFields(): void
    {
        $off = $this->serialiseUsers('name,email,status,display_label');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureUserColumns('name,email,status,display_label');

        self::assertNotContains('*', $columns);
        self::assertContains('users.name', $columns);
        self::assertContains('users.email', $columns);
        self::assertLessThan(count($this->userColumns()), count($columns));
        self::assertSame($off, $this->serialiseUsers('name,email,status,display_label'));
    }

    /**
     * Test that ':all' keeps the default aggregates, a bound one included, when
     * the select is narrowed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAllTokenKeepsTheDefaultAggregatesWhenNarrowed(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        DB::enableQueryLog();

        /** @var list<array{name: string, aggregates: array<string, mixed>}> $rows */
        $rows = json_decode($this->serialiseThrough(AggregatingUserResource::class, new User, ':all'), true);
        $sql  = $this->unquote($this->lastSelectSql('users'));

        $aggregates = array_column($rows, 'aggregates', 'name');

        self::assertStringStartsWith('select users.id, users.name, (select count(*) from posts', $sql);
        self::assertSame(1, $aggregates['Alice']['posts_count']);
        self::assertTrue($aggregates['Alice']['has_published_posts']);
        self::assertSame(['posts_count' => 0, 'has_published_posts' => false], $aggregates['Bob']);
    }

    /**
     * Test that an unannotated opaque field falls back to select *.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFallsBackForUnannotatedOpaqueField(): void
    {
        $off = $this->serialiseUsers('name,email,full_label');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureUserColumns('name,email,full_label');

        self::assertSame(['*'], $columns);
        self::assertSame($off, $this->serialiseUsers('name,email,full_label'));
    }

    /**
     * Test that the safety set keeps key, soft-delete, append and order fields.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testSafetySetKeepsRelationSoftDeleteAliasAndOrder(): void
    {
        $scalarOff   = $this->serialiseArticles('title,slug,summary_excerpt', 'views:desc');
        $relationOff = $this->serialiseArticles('title,author', 'views:desc');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureArticleColumns('title,slug,summary_excerpt', 'views:desc');

        self::assertNotContains('*', $columns);
        self::assertContains('articles.id', $columns);
        self::assertContains('articles.deleted_at', $columns);
        self::assertContains('articles.views', $columns);
        self::assertContains('articles.summary', $columns);
        self::assertSame($scalarOff, $this->serialiseArticles('title,slug,summary_excerpt', 'views:desc'));

        $relationColumns = ['articles.title', 'articles.id', 'articles.deleted_at', 'articles.user_id', 'articles.views'];

        self::assertSame($relationColumns, $this->captureArticleColumns('title,author', 'views:desc'));
        self::assertSame($relationOff, $this->serialiseArticles('title,author', 'views:desc'));
    }

    /**
     * Test that an extras-loaded belongsTo keeps its foreign key when narrowed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testSafetySetRetainsParentKeyForExtrasEagerLoadedBelongsTo(): void
    {
        $off = $this->serialiseArticles('id,author_name', null);

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureArticleColumns('id,author_name', null);

        self::assertNotContains('*', $columns);
        self::assertContains('articles.user_id', $columns);
        self::assertSame($off, $this->serialiseArticles('id,author_name', null));
    }

    /**
     * Test that a field set changed after execution leaves select * in place.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLateOverrideUsesSelectStar(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseUserQuery('name,email,full_label');

        $query = $this->applyUserCriteria();

        DB::enableQueryLog();

        $user = $query->first();

        $sql = $this->lastSelectSql('users');

        self::assertNotNull($user);
        self::assertSame(['*'], $this->normaliseColumns($query->getQuery()->columns));
        self::assertStringStartsWith('select * from users', $this->unquote($sql));

        $resource = (new UserResource($user))->withFields(['status']);
        $data     = $resource->resolve(Request::create(self::TEST_URL));

        self::assertArrayHasKey('status', $data);
    }

    /**
     * Test that a narrowed select survives pagination.
     *
     * paginate('*') only applies when no columns are set on the query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNarrowedSelectSurvivesPaginate(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseUserQuery('name,email');

        DB::enableQueryLog();

        $this->applyUserCriteria()->paginate(15, '*');

        $sql = $this->lastSelectSql();

        self::assertStringContainsString('name', $this->unquote($sql));
        self::assertStringNotContainsString('select *', $sql);
    }

    /**
     * Test that narrowing reduces the columns and the fetched attributes.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNarrowingReducesColumnsAndBytes(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $offResponse = $this->serialiseArticles('title,slug,status', null);
        $offColumns  = $this->articleColumns();
        $fullModel   = $this->fetchFirstArticle();
        $fullBytes   = strlen(serialize($fullModel->getAttributes()));

        Config::set('api-toolkit.resources.narrow_columns', true);

        $onColumns     = $this->captureArticleColumns('title,slug,status', null);
        $narrowedModel = $this->fetchFirstArticle();
        $narrowedBytes = strlen(serialize($narrowedModel->getAttributes()));
        $onResponse    = $this->serialiseArticles('title,slug,status', null);

        self::assertNotContains('*', $onColumns);
        self::assertLessThan(count($offColumns), count($onColumns));
        self::assertLessThan($fullBytes, $narrowedBytes);
        self::assertSame(array_map(static fn (string $column): string => substr($column, strlen('articles.')), $onColumns), array_keys($narrowedModel->getAttributes()));
        self::assertSame($offResponse, $onResponse);
    }

    /**
     * Test that a scalar-only resource narrows unannotated and reuses its map.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testScalarOnlyResourceNarrowsWithoutAnnotationAndReusesMap(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureArticleColumns('title,slug', null);

        self::assertNotContains('*', $columns);
        self::assertContains('articles.title', $columns);

        $first  = FieldColumnMapper::for(ArticleResource::class);
        $second = FieldColumnMapper::for(ArticleResource::class);

        self::assertSame($first, $second);
    }

    /**
     * Test that an aliased scalar narrows to the key it is exposed under.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAliasedScalarSurvivesNarrowing(): void
    {
        $off = $this->serialiseAliasedUsers('id,name,email');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureAliasedUserColumns('id,name,email');

        self::assertNotContains('*', $columns);
        self::assertContains('users.email', $columns);
        self::assertNotContains('users.email_address', $columns);
        self::assertSame($off, $this->serialiseAliasedUsers('id,name,email'));
        self::assertStringContainsString('alice@example.com', $this->serialiseAliasedUsers('id,name,email'));
    }

    /**
     * Test that a plain field over a model accessor falls back to select *.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFallsBackForAPlainFieldOverAModelAccessor(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $off = $this->serialiseArticles('id,title,headline', null);

        Config::set('api-toolkit.resources.narrow_columns', true);

        self::assertSame(['*'], $this->captureArticleColumns('id,headline', null));
        self::assertSame(['*'], $this->captureArticleColumns('id,title,headline', null));
        self::assertStringContainsString('"headline":"WIDE ARTICLE"', $this->serialiseArticles('id,headline', null));
        self::assertSame($off, $this->serialiseArticles('id,title,headline', null));
    }

    /**
     * Test that a real column whose accessor reads another column falls back.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFallsBackForARealColumnWhoseAccessorReadsAnotherColumn(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $off = $this->serialiseThrough(SluggedTitleArticleResource::class, new SluggedTitleArticle, 'id,title');

        Config::set('api-toolkit.resources.narrow_columns', true);

        self::assertSame(['*'], $this->captureColumnsThrough(SluggedTitleArticleResource::class, new SluggedTitleArticle, 'id,title'));
        self::assertStringContainsString('"title":"Wide Article \/ wide-article"', $off);
        self::assertSame($off, $this->serialiseThrough(SluggedTitleArticleResource::class, new SluggedTitleArticle, 'id,title'));
    }

    /**
     * Test that declaring the accessor's columns narrows to exactly those.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNarrowsAnAccessorBackedColumnToItsDeclaredNeeds(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $off = $this->serialiseThrough(DeclaredSluggedTitleArticleResource::class, new SluggedTitleArticle, 'id,title');

        Config::set('api-toolkit.resources.narrow_columns', true);

        $columns = $this->captureColumnsThrough(DeclaredSluggedTitleArticleResource::class, new SluggedTitleArticle, 'id,title');

        self::assertSame(['articles.id', 'articles.title', 'articles.slug'], $columns);
        self::assertSame($off, $this->serialiseThrough(DeclaredSluggedTitleArticleResource::class, new SluggedTitleArticle, 'id,title'));
    }

    /**
     * Serialise the given model's rows through the resource for a field set.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $fields
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function serialiseThrough(string $resource, Model $model, string $fields): string
    {
        $rows = $this->applyCriteriaThrough($resource, $model, $fields)->get();

        return $this->encode($resource::collection($rows)->resolve(Request::create(self::TEST_URL)));
    }

    /**
     * Capture the narrowed columns set on the model's query for a field set.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $fields
     * @return array<int, string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function captureColumnsThrough(string $resource, Model $model, string $fields): array
    {
        return $this->normaliseColumns($this->applyCriteriaThrough($resource, $model, $fields)->getQuery()->columns);
    }

    /**
     * Parse a request for the resource's type and apply the criteria chain.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $fields
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyCriteriaThrough(string $resource, Model $model, string $fields): Builder
    {
        ApiQuery::parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => [$resource::RESOURCE_TYPE => $fields],
        ]));

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> */
        return $this->makeCriteria()->usingResource($resource)->apply($model);
    }

    /**
     * Serialise the seeded users through the aliased-scalar resource.
     *
     * @param  string  $fields
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function serialiseAliasedUsers(string $fields): string
    {
        $this->parseAliasedUserQuery($fields);

        $users = $this->applyAliasedUserCriteria()->get();

        return $this->encode(AliasedScalarUserResource::collection($users)->resolve(Request::create(self::TEST_URL)));
    }

    /**
     * Capture the narrowed column names set on the aliased-scalar user query.
     *
     * @param  string  $fields
     * @return array<int, string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function captureAliasedUserColumns(string $fields): array
    {
        $this->parseAliasedUserQuery($fields);

        return $this->normaliseColumns($this->applyAliasedUserCriteria()->getQuery()->columns);
    }

    /**
     * Parse an aliased-scalar user request for the given field set.
     *
     * @param  string  $fields
     * @return void
     */
    private function parseAliasedUserQuery(string $fields): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['aliased_users' => $fields],
        ]);

        ApiQuery::parse($request);
    }

    /**
     * Apply the criteria chain to a user query bound to the aliased resource.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyAliasedUserCriteria(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User> */
        return $this->makeCriteria()->usingResource(AliasedScalarUserResource::class)->apply(new User);
    }

    /**
     * Serialise the seeded users for the given user field set.
     *
     * @param  string  $fields
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyUserCriteria(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User> */
        return $this->makeCriteria()->usingResource(UserResource::class)->apply(new User);
    }

    /**
     * Apply the criteria chain to an article query.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Article>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
            $query = $entry['query'];

            if (str_starts_with($query, 'select') && ($table === '' || str_contains($this->unquote($query), 'from ' . $table))) {
                return $query;
            }
        }

        return '';
    }

    /**
     * Strip identifier quotes so SQL assertions are driver-agnostic.
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
     * Fetch the first article under the current narrowing config.
     *
     * @return \Tests\Fixtures\Models\Article
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * A null list and a withCount ['table.*', Expression] both collapse to the
     * wildcard.
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
