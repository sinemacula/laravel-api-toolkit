<?php

declare(strict_types = 1);

namespace Benchmarks\Query;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\UserGraphSeeder;

/**
 * Benchmarks reading a cursor page at the start of a 1,000-row table and 20
 * pages in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(20)]
final class CursorPageBench
{
    /** @var string The page size */
    private const string LIMIT = '50';

    /** @var \Tests\Fixtures\Repositories\UserRepository The repository the pages are read through */
    private UserRepository $repository;

    /** @var \Illuminate\Http\Request The request for the page */
    private Request $request;

    /**
     * Seed the table and follow the cursor through the requested page.
     *
     * @param  array{page: int}  $params
     * @return void
     */
    public function setUp(array $params): void
    {
        $app = BenchmarkApplication::create();

        UserGraphSeeder::seed(1000);

        $this->repository = $app->make(UserRepository::class);

        $cursor = null;

        for ($page = 1; $page <= $params['page']; $page++) {
            $this->request = self::pageRequest($cursor);
            $cursor        = $this->readPage()->nextCursor()?->encode();
        }
    }

    /**
     * Read the page.
     *
     * @return void
     */
    #[Bench\ParamProviders('providePages')]
    public function benchReadPage(): void
    {
        $this->readPage();
    }

    /**
     * Provide the pages read.
     *
     * @return \Generator<string, array{page: int}>
     */
    public function providePages(): \Generator
    {
        yield 'page 1' => ['page' => 1];
        yield 'page 20' => ['page' => 20];
    }

    /**
     * Parse the request and read its page.
     *
     * @return \Illuminate\Pagination\CursorPaginator<int, \Tests\Fixtures\Models\User>
     */
    private function readPage(): CursorPaginator
    {
        ApiQuery::parse($this->request);

        /** @var \Illuminate\Pagination\CursorPaginator<int, \Tests\Fixtures\Models\User> */
        return $this->repository->usingResource(UserResource::class)->withApiCriteria()->paginate();
    }

    /**
     * Build the request for the page after the given cursor.
     *
     * @param  string|null  $cursor
     * @return \Illuminate\Http\Request
     */
    private static function pageRequest(?string $cursor): Request
    {
        $query = ['pagination' => 'cursor', 'limit' => self::LIMIT];

        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        return Request::create('/users', 'GET', $query);
    }
}
