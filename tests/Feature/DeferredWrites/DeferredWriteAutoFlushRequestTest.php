<?php

declare(strict_types = 1);

namespace Tests\Feature\DeferredWrites;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Feature test proving a config-wired pool limit auto-flushes mid-request.
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

        Route::post('/auto-flush-users', function (DeferrableUserRepository $repository): JsonResponse {

            $repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);

            return response()->json([
                'persisted_during_request' => DB::table('users')->count(),
            ], 202);
        });
    }

    /**
     * Test that reaching the pool limit auto-flushes while the trailing row
     * waits for the boundary.
     *
     * @return void
     */
    public function testPoolLimitAutoFlushesMidRequest(): void
    {
        $response = $this->postJson('/auto-flush-users');

        $response->assertStatus(202);

        $response->assertJsonPath('persisted_during_request', 2);

        self::assertSame(3, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Carol')->count());

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $pool */
        $pool = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that rows a failed auto-flush kept are retried once per pool limit
     * of new rows mid-request, while the new rows insert as full chunks.
     *
     * @return void
     */
    public function testRowsAFailedAutoFlushKeptAreNotRetriedOnEveryDeferral(): void
    {
        Config::set('api-toolkit.deferred_writes.pool_limit', 3);
        Config::set('api-toolkit.deferred_writes.chunk_size', 3);

        User::create(['name' => 'Taken', 'email' => 'taken@example.com']);

        Route::post('/retrying-users', function (DeferrableUserRepository $repository): JsonResponse {

            foreach (range(1, 3) as $i) {
                $repository->defer(['name' => "Duplicate {$i}", 'email' => 'taken@example.com']);
            }

            foreach (range(1, 6) as $i) {
                $repository->defer(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
            }

            return response()->json(['persisted_during_request' => DB::table('users')->count()], 202);
        });

        $inserts = 0;

        DB::connection()->beforeExecuting(function (string $query) use (&$inserts): void {
            $inserts += (int) str_starts_with($query, 'insert into');
        });

        $this->postJson('/retrying-users')->assertStatus(202)->assertJsonPath('persisted_during_request', 7);

        self::assertSame(6, $inserts);
        self::assertSame(7, DB::table('users')->count());
    }
}
