<?php

declare(strict_types = 1);

namespace Benchmarks\Resources;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use Tests\Fixtures\Resources\UserResource;

/**
 * Benchmarks planning the eager loads of a cyclic relation graph, on a memo
 * miss and a memo hit.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(100)]
final class EagerLoadPlannerBench
{
    /** @var array<int, string> The user fields requested */
    private const array FIELDS = ['name', 'organization', 'posts'];

    /**
     * Select the users' organization and posts, and each post's author and
     * tags; the planner stops at the author, whose posts would close the cycle.
     *
     * @return void
     */
    public function setUp(): void
    {
        BenchmarkApplication::create();

        ApiQuery::parse(Request::create('/users', 'GET', [
            'fields' => [
                'users'         => 'name,organization,posts',
                'posts'         => 'title,user,tags',
                'tags'          => 'name',
                'organizations' => 'name',
            ],
        ]));

        EagerLoadPlanner::buildEagerLoadMap(UserResource::class, self::FIELDS);
    }

    /**
     * Plan the eager loads with an empty memo.
     *
     * @return void
     */
    public function benchMemoMiss(): void
    {
        EagerLoadPlanner::clearCache();
        EagerLoadPlanner::buildEagerLoadMap(UserResource::class, self::FIELDS);
    }

    /**
     * Plan the eager loads from the memo.
     *
     * @return void
     */
    public function benchMemoHit(): void
    {
        EagerLoadPlanner::buildEagerLoadMap(UserResource::class, self::FIELDS);
    }
}
