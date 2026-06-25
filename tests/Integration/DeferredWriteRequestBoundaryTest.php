<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
 * Integration tests for the deferred-write flush across a simulated
 * request boundary.
 *
 * Exercises the WritePool and WritePoolFlushSubscriber lifecycle the
 * way a consuming application does: records are buffered through a
 * Deferrable repository inside a route handler, the kernel completes
 * the request, and the subscriber flushes the pool when the
 * RequestHandled lifecycle event fires.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversTrait(Deferrable::class)]
final class DeferredWriteRequestBoundaryTest extends TestCase
{
    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/deferred-users', function (DeferrableUserRepository $repository): JsonResponse {

            $repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

            return response()->json([
                'persisted_during_request' => DB::table('users')->count(),
            ], 202);
        });

        Route::post('/api/deferred-single', function (DeferrableUserRepository $repository): Response {

            /** @var string $name */
            $name = request()->input('name');

            $repository->defer(['name' => $name, 'email' => $name . '@example.com', 'password' => 'secret']);

            return response()->noContent();
        });
    }

    /**
     * Test that records deferred during a request are flushed to the
     * database when the request lifecycle completes.
     *
     * @return void
     */
    public function testDeferredWritesAreFlushedWhenRequestCompletes(): void
    {
        $response = $this->postJson('/api/deferred-users');

        $response->assertStatus(202);

        // The route handler observed an empty table: deferred records
        // must not be persisted while the request is still in flight.
        $response->assertJsonPath('persisted_during_request', 0);

        self::assertSame(2, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Alice')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Bob')->count());
    }

    /**
     * Test that the request boundary flush leaves the scoped write
     * pool empty for subsequent work.
     *
     * @return void
     */
    public function testWritePoolIsEmptyAfterRequestBoundaryFlush(): void
    {
        $this->postJson('/api/deferred-users')->assertStatus(202);

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $pool */
        $pool = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertTrue($pool->isEmpty());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that sequential requests each flush their own deferred
     * records at their own request boundary.
     *
     * @return void
     */
    public function testSequentialRequestsFlushIndependently(): void
    {
        $this->postJson('/api/deferred-single', ['name' => 'Carol'])->assertNoContent();

        self::assertSame(1, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Carol')->count());

        $this->postJson('/api/deferred-single', ['name' => 'Dave'])->assertNoContent();

        self::assertSame(2, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Dave')->count());
    }
}
