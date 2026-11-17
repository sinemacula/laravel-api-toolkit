<?php

declare(strict_types = 1);

namespace Benchmarks\Resources;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\UserGraphSeeder;

/**
 * Benchmarks reading and rendering a page whose two embedded to-many levels are
 * each filled to the default relation limit.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(1)]
final class NestedFanOutBench
{
    /** @var \Tests\Fixtures\Repositories\UserRepository The repository the page is read through */
    private UserRepository $repository;

    /** @var \Illuminate\Http\Request The request embedding both levels */
    private Request $request;

    /**
     * Seed 20 users with 25 posts of 25 tags each.
     *
     * @return void
     */
    public function setUp(): void
    {
        $app = BenchmarkApplication::create();

        UserGraphSeeder::seed(20, 25, 25);

        $this->repository = $app->make(UserRepository::class);
        $this->request    = Request::create('/users', 'GET', [
            'limit'  => '100',
            'fields' => ['users' => 'name,organization,posts', 'posts' => 'title,tags', 'tags' => 'name'],
        ]);

        // Report the request's peak, not the seeding's
        memory_reset_peak_usage();
    }

    /**
     * Read the page and encode it.
     *
     * @return void
     */
    public function benchReadAndRender(): void
    {
        ApiQuery::parse($this->request);

        $users = $this->repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

        json_encode((new ApiResourceCollection($users, UserResource::class))->resolve($this->request));
    }
}
