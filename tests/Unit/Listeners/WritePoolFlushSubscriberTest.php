<?php

namespace Tests\Unit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\TestCase;

/**
 * Tests for the WritePoolFlushSubscriber.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushSubscriber::class)]
class WritePoolFlushSubscriberTest extends TestCase
{
    /**
     * Test that subscribe registers a listener for RequestHandled.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForRequestHandled(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(RequestHandled::class));
    }

    /**
     * Test that subscribe registers a listener for CommandFinished.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForCommandFinished(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(CommandFinished::class));
    }

    /**
     * Test that subscribe registers a listener for JobProcessed.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForJobProcessed(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(JobProcessed::class));
    }

    /**
     * Test that subscribe registers a listener for JobFailed.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForJobFailed(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(JobFailed::class));
    }

    /**
     * Test that handleFlush resolves the WritePool from the container and calls flush.
     *
     * @return void
     */
    public function testHandleFlushResolvesWritePoolFromContainerAndCallsFlush(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('test_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->once()
            ->andReturn($pool);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        static::assertTrue($pool->isEmpty());
    }
}
