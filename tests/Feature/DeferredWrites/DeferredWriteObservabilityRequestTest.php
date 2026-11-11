<?php

declare(strict_types = 1);

namespace Tests\Feature\DeferredWrites;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use Tests\TestCase;

/**
 * Feature test proving lastAutoFlushResult surfaces a non-throwing mid-request
 * auto-flush failure.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
#[CoversClass(WritePoolFlushResult::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
final class DeferredWriteObservabilityRequestTest extends TestCase
{
    /**
     * Set up each test with a single-record pool limit and a deferring route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.deferred_writes.on_failure', 'collect');
        Config::set('api-toolkit.deferred_writes.pool_limit', 1);

        Route::post('/observable-flush', function (): JsonResponse {

            $pool = app(WritePool::class);

            // The missing table fails the auto-flush without throwing
            $pool->add('nonexistent_table', ['col' => 'val']);

            return response()->json([
                'failure_count' => $pool->lastAutoFlushResult()?->failureCount(),
            ], 202);
        });
    }

    /**
     * Test that the scoped pool's lastAutoFlushResult reports the failed
     * auto-flush count.
     *
     * @return void
     */
    public function testLastAutoFlushResultReportsFailureCount(): void
    {
        Exceptions::fake();

        $response = $this->postJson('/observable-flush');

        $response->assertStatus(202);
        $response->assertJsonPath('failure_count', 1);
    }
}
