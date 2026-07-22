<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature test covering the deferred-write failure strategies over live
 * requests.
 *
 * Proves each config-wired on_failure posture behaves as documented when a
 * flush fails: throw escalates a boundary flush failure loudly (and surfaces
 * the exception only when rethrow_at_boundary is set), log drops the failed
 * records after logging, and an in-handler auto-flush failure under throw
 * reaches the client as a server error rather than the accepted response.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversClass(WritePoolFlushException::class)]
#[CoversClass(FlushStrategy::class)]
final class DeferredWriteFailureStrategyRequestTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the toolkit exception handler and deferring routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        // Buffers a record for a table that does not exist so the flush fails.
        Route::post('/deferred-invalid-boundary', function (): JsonResponse {

            app(WritePool::class)->add('nonexistent_table', ['col' => 'val']);

            return response()->json(['accepted' => true], 202);
        });

        // Buffers a record for a missing table with the pool limit at one, so
        // the auto-flush fires (and may throw) inside the handler itself.
        Route::post('/deferred-invalid-handler', function (): JsonResponse {

            app(WritePool::class)->add('nonexistent_table', ['col' => 'val']);

            return response()->json(['accepted' => true], 202);
        });
    }

    /**
     * Test that the throw strategy escalates a boundary flush failure while the
     * request still returns its accepted response.
     *
     * @return void
     */
    public function testThrowStrategyEscalatesBoundaryFailureWithoutRethrow(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'throw');

        Event::fake([WritePoolFlushFailed::class]);

        Log::spy();

        $this->postJson('/deferred-invalid-boundary')->assertStatus(202);

        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that the throw strategy surfaces the flush exception at the boundary
     * when rethrow_at_boundary is enabled.
     *
     * @return void
     */
    public function testThrowStrategyRethrowsAtBoundaryWhenConfigured(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'throw');
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        Log::spy();

        // The boundary flush runs after the response is produced, so the
        // re-thrown exception surfaces out of the test kernel rather than as a
        // rendered response.
        $this->expectException(WritePoolFlushException::class);

        $this->postJson('/deferred-invalid-boundary');
    }

    /**
     * Test that the log strategy drops the failed records after logging while
     * the request returns its accepted response.
     *
     * @return void
     */
    public function testLogStrategyDropsAndLogsFailedRecords(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'log');

        Log::spy();

        $this->postJson('/deferred-invalid-boundary')->assertStatus(202);

        Log::shouldHaveReceived('error')->once(); // @phpstan-ignore staticMethod.notFound

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $pool */
        $pool = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that an in-handler auto-flush failure under the throw strategy
     * reaches the client as a server error rather than the accepted response.
     *
     * @return void
     */
    public function testThrowStrategyRaisesServerErrorFromHandlerAtPoolLimit(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'throw');
        Config::set('api-toolkit.deferred_writes.pool_limit', 1);

        Log::spy();

        $this->postJson('/deferred-invalid-handler')->assertStatus(500);
    }
}
