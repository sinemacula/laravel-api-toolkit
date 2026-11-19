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
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\WalksPaginationLinks;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Resources\PagedArticleResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests following every page link of a read that filters, searches,
 * widens to trashed rows, narrows its fields and sorts at once.
 *
 * Decoys fail the search, the `$or` filter, or match only while trashed, so a
 * seek predicate escaping the filter group or a link dropping a parameter
 * changes the rows a later page returns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
final class FilteredPaginationWalkTest extends TestCase
{
    use RegistersApiExceptionHandler, WalksPaginationLinks;

    /** @var array<int, string> The matching titles, by views descending then key descending */
    private const array MATCHES = ['guide i', 'guide e', 'guide b', 'guide j', 'guide h', 'guide f', 'guide a'];

    /**
     * Set up a searchable, soft-deleting articles route and seed matches and
     * decoys.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [PagedArticleResource::class]);

        $this->registerApiExceptionHandler();

        $connection = DB::connection();

        $this->app?->make(SearchDriverRegistry::class)->override($connection->getDriverName(), new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [$connection->getName()]);

        Route::middleware(ParseApiQuery::class)->get('/articles', function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(PagedArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, PagedArticleResource::class);
        });

        $author = User::create(['name' => 'Author', 'email' => 'author@example.com']);

        $rows = [
            ['guide a', 'published', 50],
            ['guide b', 'draft', 150],
            ['guide c', 'draft', 50],
            ['note d', 'published', 150],
            ['guide e', 'published', 150],
            ['guide f', 'published', 50],
            ['guide g', 'draft', 10],
            ['guide h', 'archived', 100],
            ['guide i', 'published', 150],
            ['guide j', 'draft', 100],
        ];

        foreach ($rows as [$title, $status, $views]) {
            Article::create([
                'user_id' => $author->id,
                'title'   => $title,
                'slug'    => str_replace(' ', '-', $title),
                'body'    => '',
                'summary' => '',
                'status'  => $status,
                'views'   => $views,
            ]);
        }

        Article::query()->whereIn('title', ['guide e', 'guide f', 'guide g'])->delete();
    }

    /**
     * Test that a cursor walk visits each match once both ways and every link
     * keeps the read's parameters.
     *
     * @return void
     */
    public function testCursorWalkVisitsEachMatchOnceKeepingEveryParameter(): void
    {
        $parameters = $this->parameters() + ['pagination' => 'cursor'];

        $pages = $this->walk($this->getJson('/articles?' . http_build_query($parameters)), 'title', $parameters);

        self::assertSame(array_chunk(self::MATCHES, 2), $pages);
    }

    /**
     * Test that an offset walk visits each match once both ways and every link
     * keeps the read's parameters.
     *
     * @return void
     */
    public function testOffsetWalkVisitsEachMatchOnceKeepingEveryParameter(): void
    {
        $parameters = $this->parameters();

        $pages = $this->walk($this->getJson('/articles?' . http_build_query($parameters)), 'title', $parameters);

        self::assertSame(array_chunk(self::MATCHES, 2), $pages);
    }

    /**
     * Test that a followed offset page equals the direct read of that page,
     * fieldset narrowed.
     *
     * @return void
     */
    public function testFollowedOffsetPageEqualsTheDirectRead(): void
    {
        $parameters = $this->parameters();
        $next       = $this->getJson('/articles?' . http_build_query($parameters))->json('links.next');

        self::assertIsString($next);

        $followed = $this->getJson((string) parse_url($next, PHP_URL_PATH) . '?' . parse_url($next, PHP_URL_QUERY));
        $direct   = $this->getJson('/articles?' . http_build_query($parameters + ['page' => '2']));

        $followed->assertOk();
        $direct->assertOk();

        self::assertSame($direct->json('data'), $followed->json('data'));
        self::assertSame(['guide b', 'guide j'], array_column((array) $followed->json('data'), 'title'));

        $followed->assertJsonMissingPath('data.0.status');
        $followed->assertJsonMissingPath('data.1.status');
    }

    /**
     * Return the read's parameters, as a client would send them.
     *
     * @return array<string, array<string, string>|string>
     */
    private function parameters(): array
    {
        return [
            'filters' => (string) json_encode(['$or' => [['status' => 'published'], ['views' => ['$ge' => 100]]]]),
            'search'  => 'guide',
            'trashed' => 'with',
            'fields'  => ['paged_articles' => 'title,views'],
            'order'   => 'views:desc',
            'limit'   => '2',
        ];
    }
}
