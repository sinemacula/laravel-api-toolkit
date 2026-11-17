<?php

declare(strict_types = 1);

namespace Tests\Feature\Lifecycle;

use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\TestCase;

/**
 * Feature test that a job on the deferred queue driver, which runs inside the
 * request after the response, does not reset the request's state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueueFlushSubscriber::class)]
#[CoversClass(RuntimeContext::class)]
final class DeferredQueueRequestTest extends TestCase
{
    /**
     * Test that the parsed query stays readable after a deferred job ran and
     * the toolkit state is not reset.
     *
     * @return void
     */
    public function testDeferredJobLeavesTheParsedQueryReadableAfterItRuns(): void
    {
        Config::set('queue.connections.deferred', ['driver' => 'deferred']);

        $attempts = [];
        $flushes  = 0;
        $fields   = [];

        Event::listen(JobAttempted::class, static function (JobAttempted $event) use (&$attempts): void {
            $attempts[] = $event->connectionName;
        });

        Event::listen(CacheFlushed::class, static function () use (&$flushes): void {
            $flushes++;
        });

        Route::middleware(ParseApiQuery::class)->get('/deferred-job', static function () use (&$fields): Response {

            dispatch(static function (): void {})->onConnection('deferred');

            app()->terminating(static function () use (&$fields): void {
                $fields = ApiQuery::getFields('users');
            });

            return response()->noContent();
        });

        $this->get('/deferred-job?fields[users]=name')->assertNoContent();

        self::assertSame(['deferred'], $attempts);
        self::assertSame(['name'], $fields);
        self::assertSame(0, $flushes);
    }
}
