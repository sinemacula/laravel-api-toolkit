<?php

declare(strict_types = 1);

namespace Benchmarks\Query;

use Benchmarks\Support\BenchmarkApplication;
use Benchmarks\Support\WideUserResource;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Fixtures\Models\User;

/**
 * Benchmarks parsing filters at the node and parse-depth caps, and applying a
 * query at the node, sort-key, aggregate and page-size caps to a builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(50)]
final class ApiQueryParserBench
{
    /** @var string The URI every request is made to */
    private const string URI = '/users';

    /** @var \Illuminate\Foundation\Application The application resolving the criteria */
    private Application $app;

    /** @var \Illuminate\Http\Request A filter visiting exactly the node cap */
    private Request $atNodeCap;

    /** @var \Illuminate\Http\Request A filter nested exactly to the parse-depth cap */
    private Request $atParseDepthCap;

    /** @var \Illuminate\Http\Request A request at the node, sort-key, aggregate and page-size caps */
    private Request $atCaps;

    /**
     * Build the requests and warm the column metadata the criteria read.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function setUp(): void
    {
        $this->app = BenchmarkApplication::create();

        $nodes = (string) json_encode(['$has' => array_fill(0, 99, 'posts')]);
        $depth = ['name' => ['$eq' => 'User 1']];

        for ($level = 0; $level < 7; $level++) {
            $depth = ['$or' => [$depth]];
        }

        $this->atNodeCap       = Request::create(self::URI, 'GET', ['filters' => $nodes]);
        $this->atParseDepthCap = Request::create(self::URI, 'GET', ['filters' => json_encode($depth)]);
        $this->atCaps          = Request::create(self::URI, 'GET', [
            'fields'     => ['wide_users' => 'name,aggregates'],
            'filters'    => $nodes,
            'order'      => 'name:asc,id:desc,created_at:asc',
            'aggregates' => ['wide_users' => 'posts_count,has_posts,posts_sum_id,posts_avg_id,posts_min_created_at'],
            'limit'      => '100',
        ]);

        $this->benchApplyAtCaps();
    }

    /**
     * Parse the filter at the node cap.
     *
     * @return void
     */
    public function benchParseAtNodeCap(): void
    {
        ApiQuery::parse($this->atNodeCap);
    }

    /**
     * Parse the filter at the parse-depth cap.
     *
     * @return void
     */
    public function benchParseAtParseDepthCap(): void
    {
        ApiQuery::parse($this->atParseDepthCap);
    }

    /**
     * Parse the request at the node, sort-key, aggregate and page-size caps and
     * apply it to a user query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function benchApplyAtCaps(): void
    {
        ApiQuery::parse($this->atCaps);

        $this->app->make(ApiCriteria::class)->usingResource(WideUserResource::class)->apply(new User)->toSql();
    }
}
