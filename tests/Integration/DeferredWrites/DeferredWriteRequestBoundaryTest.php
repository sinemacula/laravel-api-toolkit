<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

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
 * Integration tests for the deferred-write flush across a request boundary.
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

        Route::post('/deferred-users', function (DeferrableUserRepository $repository): JsonResponse {

            $repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

            return response()->json([
                'persisted_during_request' => DB::table('users')->count(),
            ], 202);
        });

        Route::post('/deferred-single', function (DeferrableUserRepository $repository): Response {

            /** @var string $name */
            $name = request()->input('name');

            $repository->defer(['name' => $name, 'email' => $name . '@example.com', 'password' => 'secret']);

            return response()->noContent();
        });
    }

    /**
     * Test that records deferred during a request are flushed at its end.
     *
     * @return void
     */
    public function testDeferredWritesAreFlushedWhenRequestCompletes(): void
    {
        $response = $this->postJson('/deferred-users');

        $response->assertStatus(202);

        // Deferred records must not persist while the request is in flight
        $response->assertJsonPath('persisted_during_request', 0);

        self::assertSame(2, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Alice')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Bob')->count());
    }

    /**
     * Test that the request boundary flush leaves the write pool empty.
     *
     * @return void
     */
    public function testWritePoolIsEmptyAfterRequestBoundaryFlush(): void
    {
        $this->postJson('/deferred-users')->assertStatus(202);

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $pool */
        $pool = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertTrue($pool->isEmpty());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that sequential requests each flush their own deferred records.
     *
     * @return void
     */
    public function testSequentialRequestsFlushIndependently(): void
    {
        $this->postJson('/deferred-single', ['name' => 'Carol'])->assertNoContent();

        self::assertSame(1, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Carol')->count());

        $this->postJson('/deferred-single', ['name' => 'Dave'])->assertNoContent();

        self::assertSame(2, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Dave')->count());
    }
}
