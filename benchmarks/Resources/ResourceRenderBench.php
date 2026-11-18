<?php

declare(strict_types = 1);

namespace Benchmarks\Resources;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GuardedUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\UserGraphSeeder;

/**
 * Benchmarks rendering a loaded page of resources to JSON.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(3)]
final class ResourceRenderBench
{
    /** @var array<string, array{class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>, array<string, mixed>}> The resource and query of each shape */
    private const array SHAPES = [
        'scalars'    => [UserResource::class, ['fields' => ['users' => 'name,email,status,created_at']]],
        'nested'     => [UserResource::class, ['fields' => ['users' => 'name,organization,posts', 'posts' => 'title,tags', 'tags' => 'name']]],
        'aggregates' => [UserResource::class, ['fields' => ['users' => 'name,aggregates'], 'aggregates' => ['users' => 'posts_count,posts_sum_id']]],
        'guarded'    => [GuardedUserResource::class, ['fields' => ['guarded_users' => 'name,email'], 'show' => 'yes']],
    ];

    /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> The resource rendered */
    private string $resource;

    /** @var \Illuminate\Http\Request The request the page renders for */
    private Request $request;

    /** @var \Illuminate\Support\Collection<int, object> The loaded page */
    private Collection $users;

    /**
     * Load the page of the shape's resource.
     *
     * @param  array{rows: int, shape: string}  $params
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function setUp(array $params): void
    {
        $app = BenchmarkApplication::create();

        UserGraphSeeder::seed(1000, 2, 2);

        [$this->resource, $query] = self::SHAPES[$params['shape']];

        $this->request = Request::create('/users', 'GET', $query);

        ApiQuery::parse($this->request);

        $this->users = $app->make(ApiCriteria::class)->usingResource($this->resource)->apply(new User)->limit($params['rows'])->get();
    }

    /**
     * Render the page and encode it.
     *
     * @return void
     */
    #[Bench\ParamProviders(['provideRows', 'provideShapes'])]
    public function benchRender(): void
    {
        json_encode($this->resource::collection($this->users)->resolve($this->request));
    }

    /**
     * Provide the page sizes.
     *
     * @return \Generator<string, array{rows: int}>
     */
    public function provideRows(): \Generator
    {
        yield '100 rows' => ['rows' => 100];
        yield '1000 rows' => ['rows' => 1000];
    }

    /**
     * Provide the field shapes.
     *
     * @return \Generator<string, array{shape: string}>
     */
    public function provideShapes(): \Generator
    {
        foreach (array_keys(self::SHAPES) as $shape) {
            yield $shape => ['shape' => $shape];
        }
    }
}
