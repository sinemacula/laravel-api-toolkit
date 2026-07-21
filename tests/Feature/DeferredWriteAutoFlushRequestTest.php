<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Feature test proving a config-wired pool limit auto-flushes mid-request.
 *
 * With the deferred-writes pool limit bound from config to two, a route that
 * defers three rows through the config-bound repository sees the first two
 * persisted while the request is still in flight (the auto-flush fired when the
 * buffer reached the limit) and the trailing row persisted only once the
 * request lifecycle boundary flush runs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversTrait(Deferrable::class)]
final class DeferredWriteAutoFlushRequestTest extends TestCase
{
    /**
     * Set up each test with a config-bound pool limit and a deferring route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.deferred_writes.pool_limit', 2);

        Route::post('/api/auto-flush-users', function (DeferrableUserRepository $repository): JsonResponse {

            $repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);

            return response()->json([
                'persisted_during_request' => DB::table('users')->count(),
            ], 202);
        });
    }

    /**
     * Test that reaching the config-bound pool limit auto-flushes mid-request
     * while the trailing row waits for the boundary flush.
     *
     * @return void
     */
    public function testPoolLimitAutoFlushesMidRequest(): void
    {
        $response = $this->postJson('/api/auto-flush-users');

        $response->assertStatus(202);

        // Two rows crossed the pool limit and were auto-flushed while the
        // request was still executing; the third stayed buffered.
        $response->assertJsonPath('persisted_during_request', 2);

        self::assertSame(3, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Carol')->count());

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $pool */
        $pool = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertTrue($pool->isEmpty());
    }
}
