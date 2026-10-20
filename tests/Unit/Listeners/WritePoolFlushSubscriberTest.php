<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Concerns\SwapsExceptionHandler;
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
final class WritePoolFlushSubscriberTest extends TestCase
{
    use SwapsExceptionHandler;

    /**
     * Set up the test environment.
     *
     * The write pool flush now invalidates the per-query cache itself; disable
     * that here so the subscriber's flush and escalation assertions observe it
     * in isolation, without a cache side effect.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);
    }

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

        self::assertTrue($events->hasListeners(RequestHandled::class));
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

        self::assertTrue($events->hasListeners(CommandFinished::class));
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

        self::assertTrue($events->hasListeners(JobProcessed::class));
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

        self::assertTrue($events->hasListeners(JobFailed::class));
    }

    /**
     * Test that dispatching RequestHandled flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingRequestHandledFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('request@example.com');

        $events->dispatch(new RequestHandled(Request::create('/test', 'GET'), new Response));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that dispatching CommandFinished flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingCommandFinishedFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('command@example.com');

        $events->dispatch(new CommandFinished('test:command', new ArrayInput([]), new NullOutput, 0));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that dispatching JobProcessed flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingJobProcessedFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('job@example.com');

        $events->dispatch(new JobProcessed('sync', self::createStub(Job::class)));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that dispatching JobFailed flushes the pool through the registered
     * listener.
     *
     * @return void
     */
    public function testDispatchingJobFailedFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('failed-job@example.com');

        $events->dispatch(new JobFailed('sync', self::createStub(Job::class), new \RuntimeException('Job failure')));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that handleFlush resolves the WritePool from the container and calls
     * flush.
     *
     * @return void
     */
    public function testHandleFlushResolvesWritePoolFromContainerAndCallsFlush(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->once()
            ->andReturn($pool);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that a collected flush failure is reported exactly once as a
     * WritePoolFlushException chained to the underlying database exception and
     * carrying the failing table in its context.
     *
     * @return void
     */
    public function testHandleFlushReportsCollectedFailureOnce(): void
    {
        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (WritePoolFlushException $e): bool => $e->getMessage() === 'WritePool flush failed: 1 chunk(s) failed out of 1 total.'
            && $e->getPrevious() instanceof QueryException
            && $e->getPrevious()       === $e->flushResult()->firstException()
            && $e->context()['tables'] === ['nonexistent_table']);
    }

    /**
     * Test that handleFlush dispatches WritePoolFlushFailed event on failure.
     *
     * @return void
     */
    public function testHandleFlushDispatchesWritePoolFlushFailedEventOnFailure(): void
    {
        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that a systemic COLLECT failure, where every table fails, is
     * reported once and dispatches a WritePoolFlushFailed event whose result
     * carries every failing table so downstream escalation can act on the full
     * set.
     *
     * @return void
     */
    public function testHandleFlushDispatchesEventCarryingEverySystemicFailure(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('another_missing_table', ['col' => 'b']);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($pool)->handleFlush();

        Exceptions::assertReportedCount(1);
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 2
            && array_key_exists('nonexistent_table', $event->flushResult->failures())
            && array_key_exists('another_missing_table', $event->flushResult->failures()));
    }

    /**
     * Test that a collected failure is still reported, and not thrown, when the
     * rethrow_at_boundary flag is enabled, since nothing was raised to rethrow.
     *
     * @return void
     */
    public function testHandleFlushReportsCollectedFailureWhenRethrowEnabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that a log strategy failure dispatches the event without reporting,
     * since its writes are disposable and each chunk is already logged.
     *
     * @return void
     */
    public function testHandleFlushDoesNotReportLogStrategyFailure(): void
    {
        Log::shouldReceive('warning')->once();

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::LOG))->handleFlush();

        Exceptions::assertNothingReported();
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->droppedRecordCount() === 1);
    }

    /**
     * Test that handleFlush neither reports nor dispatches on success.
     *
     * @return void
     */
    public function testHandleFlushDoesNotReportOrDispatchOnSuccess(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($pool)->handleFlush();

        Exceptions::assertNothingReported();
        Event::assertNotDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that a failed event dispatch after a collected failure does not
     * escape and is itself reported alongside the flush failure.
     *
     * @return void
     */
    public function testHandleFlushReportsEventDispatchFailureUnderCollect(): void
    {
        Exceptions::fake();

        Event::shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Event dispatch failed'));

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(WritePoolFlushException::class);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'Event dispatch failed');
    }

    /**
     * Test that a failed event dispatch after a raised throw strategy failure
     * does not escape and is itself reported alongside the flush failure.
     *
     * @return void
     */
    public function testHandleFlushReportsEventDispatchFailureUnderThrow(): void
    {
        Exceptions::fake();

        Event::shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Event dispatch failed'));

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(WritePoolFlushException::class);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'Event dispatch failed');
    }

    /**
     * Test that when reporting the flush failure itself fails, the failure
     * falls back to the error log and the event is still dispatched.
     *
     * @return void
     */
    public function testHandleFlushStillDispatchesWhenReportingFails(): void
    {
        $handler = $this->useThrowingExceptionHandler();

        Log::shouldReceive('error')
            ->once()
            ->with('WritePool boundary flush failed', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof WritePoolFlushException,
            ));

        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        self::assertCount(1, $handler->reported);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that an unexpected throwable during pool resolution is caught and
     * reported without crashing the boundary.
     *
     * @return void
     */
    public function testHandleFlushReportsWhenPoolResolutionThrows(): void
    {
        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andThrow(new \RuntimeException('Database connection lost'));

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        (new WritePoolFlushSubscriber($container))->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'Database connection lost');
        Event::assertNotDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that a WritePoolFlushException raised by the throw strategy is
     * reported once as the raised instance and the event is dispatched.
     *
     * @return void
     */
    public function testHandleFlushReportsThrowStrategyFailureOnce(): void
    {
        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (WritePoolFlushException $e): bool => $e->getPrevious() instanceof QueryException
            && $e->getPrevious()       === $e->flushResult()->firstException()
            && basename($e->getFile()) === 'WritePool.php');
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that the subscriber reports rather than re-throws a flush exception
     * when the rethrow_at_boundary flag is disabled.
     *
     * @return void
     */
    public function testHandleFlushDoesNotRethrowWhenRethrowDisabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', false);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that the subscriber reports rather than re-throws a flush exception
     * when the rethrow_at_boundary key is absent, so the safe default keeps the
     * boundary from hard-crashing.
     *
     * @return void
     */
    public function testHandleFlushDoesNotRethrowWhenRethrowKeyAbsent(): void
    {
        $deferred = (array) Config::get('api-toolkit.deferred_writes');
        unset($deferred['rethrow_at_boundary']);
        Config::set('api-toolkit.deferred_writes', $deferred);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that the subscriber re-throws the raised flush exception after
     * dispatching the event, leaving its reporting to the boundary, when the
     * rethrow_at_boundary flag is enabled.
     *
     * @return void
     */
    public function testHandleFlushRethrowsWithoutReportingWhenRethrowEnabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $thrown = null;

        try {
            $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();
        } catch (WritePoolFlushException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(WritePoolFlushException::class, $thrown);
        self::assertInstanceOf(QueryException::class, $thrown->getPrevious());
        Exceptions::assertNothingReported();
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult === $thrown->flushResult());
    }

    /**
     * Build a pool holding one row bound for a table that does not exist.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePool
     */
    private function failingPool(FlushStrategy $strategy = FlushStrategy::COLLECT): WritePool
    {
        $pool = new WritePool(500, 10000, $strategy);
        $pool->add('nonexistent_table', ['col' => 'val']);

        return $pool;
    }

    /**
     * Build a subscriber whose container resolves the given pool.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePool  $pool
     * @return \SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber
     */
    private function subscriberFlushing(WritePool $pool): WritePoolFlushSubscriber
    {
        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        return new WritePoolFlushSubscriber($container);
    }

    /**
     * Subscribe a fresh dispatcher with a pool containing one pending row.
     *
     * @param  string  $email
     * @return array{0: \Illuminate\Events\Dispatcher, 1: \SineMacula\ApiToolkit\Repositories\Concerns\WritePool}
     */
    private function subscribeWithPooledRow(string $email): array
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Dave', 'email' => $email, 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->subscribe($events);

        return [$events, $pool];
    }
}
