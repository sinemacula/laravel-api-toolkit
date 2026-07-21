<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Feature test proving transactional deferred writes roll back all-or-nothing.
 *
 * With the transactional flag config-wired on and a chunk size of one, a route
 * defers two rows whose second entry violates the unique email constraint. When
 * the boundary flush runs the whole table's inserts are applied inside a single
 * transaction, so the constraint violation rolls the entire table back to zero
 * rows and the failure is escalated through the dispatched event.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversTrait(Deferrable::class)]
final class DeferredWriteTransactionalRequestTest extends TestCase
{
    /**
     * Set up each test with transactional deferred writes and a deferring
     * route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.deferred_writes.transactional', true);
        Config::set('api-toolkit.deferred_writes.chunk_size', 1);

        Route::post('/api/transactional-users', function (DeferrableUserRepository $repository): JsonResponse {

            $repository->defer(['name' => 'Alice', 'email' => 'clash@example.com', 'password' => 'secret']);
            $repository->defer(['name' => 'Bob', 'email' => 'clash@example.com', 'password' => 'secret']);

            return response()->json(['accepted' => true], 202);
        });
    }

    /**
     * Test that a unique-constraint violation inside a transactional flush
     * rolls the whole table back and escalates the failure.
     *
     * @return void
     */
    public function testTransactionalFlushRollsBackWholeTable(): void
    {
        Event::fake([WritePoolFlushFailed::class]);

        Log::spy();

        $this->postJson('/api/transactional-users')->assertStatus(202);

        self::assertSame(0, DB::table('users')->count());

        Event::assertDispatched(WritePoolFlushFailed::class);
    }
}
