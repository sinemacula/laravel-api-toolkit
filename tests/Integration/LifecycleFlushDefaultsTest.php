<?php

namespace Tests\Integration;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\TestCase;
use Illuminate\Events\Dispatcher;

/**
 * Integration harness: cross-request metadata staleness under Octane and queue.
 *
 * Proves that stale metadata is cleared at the correct lifecycle boundary,
 * that php-fpm does not engage, that opt-out is honoured, and that non-toolkit
 * keys on the shared store survive the scoped flush.
 *
 * Every test sets the relevant config and $_SERVER state EXPLICITLY so the
 * mechanism is validated before the shipped default is flipped in Tier 4.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
final class LifecycleFlushDefaultsTest extends TestCase
{
    /** @var bool Whether LARAVEL_OCTANE was set before each test. */
    private bool $octaneWasSet;

    /**
     * Capture the initial LARAVEL_OCTANE state and resolve shared singletons.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->octaneWasSet = isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Restore the LARAVEL_OCTANE server variable after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->octaneWasSet) {
            $_SERVER['LARAVEL_OCTANE'] = 1;
        } else {
            unset($_SERVER['LARAVEL_OCTANE']);
        }

        parent::tearDown();
    }

    /**
     * Test that an Octane boundary flushes stale metadata so request 2 reads
     * the new shape rather than the memoised old shape.
     *
     * Validates AC-03 / AC-04: under a long-lived Octane worker, metadata
     * written before the request boundary must not survive into the next request.
     *
     * @return void
     */
    public function testOctaneServingFlushesStaleMetadataAcrossRequests(): void
    {
        // Arrange
        $_SERVER['LARAVEL_OCTANE'] = 1;
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $key    = 'integration:octane-staleness-test';
        $writer = $this->writer();

        // Request 1: write and confirm the old shape is memoised.
        $writer->rememberMetadataForever($key, static fn () => 'old-shape');
        static::assertSame('old-shape', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Boundary: simulate end-of-request Octane flush.
        $this->octaneListener()->handle(new \stdClass);

        // Deploy + Request 2: the memo is clear; writing the new shape must
        // return 'new-shape', not the previously memoised 'old-shape'.
        $result = $writer->rememberMetadataForever($key, static fn () => 'new-shape');

        // Assert
        static::assertSame('new-shape', $result);
        static::assertSame('new-shape', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that a queue worker boundary flushes stale metadata so job 2 reads
     * the new shape rather than the memoised old shape.
     *
     * Validates AC-06: under a long-lived queue worker, metadata must not leak
     * across job boundaries.
     *
     * @return void
     */
    public function testQueueWorkerFlushesStaleMetadataBetweenJobs(): void
    {
        // Arrange
        Config::set('queue.connections.database.driver', 'database');
        Config::set('api-toolkit.lifecycle.queue', true);

        Event::fake();

        $key    = 'integration:queue-staleness-test';
        $writer = $this->writer();

        // Job 1: write old shape and confirm memoised.
        $writer->rememberMetadataForever($key, static fn () => 'old-shape');
        static::assertSame('old-shape', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Boundary: simulate end-of-job queue flush.
        $event = new JobProcessed('database', static::createStub(Job::class));
        $this->queueSubscriber()->handleFlush($event);

        // Job 2: the memo is clear; writing the new shape must return 'new-shape'.
        $result = $writer->rememberMetadataForever($key, static fn () => 'new-shape');

        // Assert
        static::assertSame('new-shape', $result);
        static::assertSame('new-shape', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that under php-fpm (no LARAVEL_OCTANE signal), the Octane listener
     * does not perform a flush even when the config flag is on.
     *
     * Validates AC-09: the runtime gate, not the config flag, prevents flush
     * engagement outside a long-lived Octane worker.
     *
     * @return void
     */
    public function testPhpFpmRequestPerformsNoFlush(): void
    {
        // Arrange - no LARAVEL_OCTANE signal (php-fpm).
        unset($_SERVER['LARAVEL_OCTANE']);
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $key    = 'integration:php-fpm-no-flush-test';
        $writer = $this->writer();

        $writer->rememberMetadataForever($key, static fn () => 'value');
        static::assertSame('value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Act: invoke the boundary under php-fpm conditions.
        $this->octaneListener()->handle(new \stdClass);

        // Assert: the key must survive because no flush ran.
        static::assertSame('value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that the opt-out flag prevents the lifecycle flush from being wired
     * to its boundary at all.
     *
     * Validates AC-02: with the lifecycle flag off, LifecycleRegistrar does not
     * subscribe the flush, so no boundary can fire it. The queue path is the
     * representative opt-out oracle - the Octane path additionally gates on
     * class_exists(OperationTerminated), which is always false here because
     * laravel/octane is not installed, so the queue gate is the one that can be
     * isolated. The enabled control proves the assertion tracks the flag rather
     * than passing vacuously.
     *
     * @return void
     */
    public function testOptOutDisablesLifecycleFlushSubscription(): void
    {
        // Reset the dispatcher so the boot-time wiring (now default-on) does not
        // pollute the baseline being tested.
        Event::swap(new Dispatcher($this->app));

        // Opt-out: the flag is off, so the registrar must not wire the subscriber.
        Config::set('api-toolkit.lifecycle.queue', false);

        (new LifecycleRegistrar)->register();

        static::assertFalse(
            $this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class),
            'QueueFlushSubscriber must not be wired when lifecycle.queue is false',
        );

        // Control: with the flag on, the registrar wires it - proving the gate,
        // not an always-false assertion, drives the opt-out behaviour.
        Config::set('api-toolkit.lifecycle.queue', true);

        (new LifecycleRegistrar)->register();

        static::assertTrue(
            $this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class),
            'QueueFlushSubscriber must be wired when lifecycle.queue is true',
        );
    }

    /**
     * Test that a non-toolkit key written directly to the shared memo store
     * survives the scoped metadata flush while the toolkit key is cleared.
     *
     * Validates NFR-01 / AC-08: the flush must not blast non-toolkit keys off
     * the shared memo store.
     *
     * @return void
     */
    public function testSharedStoreNonToolkitKeySurvivesFlush(): void
    {
        // Arrange
        $_SERVER['LARAVEL_OCTANE'] = 1;
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $toolkitKey    = 'integration:toolkit-key';
        $nonToolkitKey = 'app:user-prefs';
        $writer        = $this->writer();

        // Write the toolkit key through the writer (registered for scoped flush).
        $writer->rememberMetadataForever($toolkitKey, static fn () => 'toolkit-value');

        // Write the non-toolkit key directly (NOT registered; must survive flush).
        Cache::memo()->rememberForever($nonToolkitKey, static fn () => 'keep-me'); // @phpstan-ignore method.notFound

        static::assertSame('toolkit-value', Cache::memo()->get($toolkitKey)); // @phpstan-ignore method.notFound
        static::assertSame('keep-me', Cache::memo()->get($nonToolkitKey)); // @phpstan-ignore method.notFound

        // Act: invoke the Octane boundary.
        $this->octaneListener()->handle(new \stdClass);

        // Assert: the toolkit key is gone; the non-toolkit key survives.
        static::assertNull(Cache::memo()->get($toolkitKey)); // @phpstan-ignore method.notFound
        static::assertSame('keep-me', Cache::memo()->get($nonToolkitKey)); // @phpstan-ignore method.notFound
    }

    /**
     * Determine whether the given event has a listener belonging to the given
     * subscriber class.
     *
     * @param  class-string  $event
     * @param  class-string  $subscriber
     * @return bool
     */
    private function hasSubscriberListener(string $event, string $subscriber): bool
    {
        assert($this->app !== null);

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->app->make('events');

        $listeners = $events->getRawListeners()[$event] ?? [];

        if (!is_iterable($listeners)) {
            return false;
        }

        foreach ($listeners as $listener) {
            if (is_array($listener) && ($listener[0] ?? null) instanceof $subscriber) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the wired MetadataCacheWriter singleton.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    private function writer(): MetadataCacheWriter
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Cache\MetadataCacheWriter */
        return $this->app->make(MetadataCacheWriter::class);
    }

    /**
     * Build an OctaneFlushListener backed by the wired CacheManager singleton.
     *
     * @return \SineMacula\ApiToolkit\Listeners\OctaneFlushListener
     */
    private function octaneListener(): OctaneFlushListener
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $cacheManager */
        $cacheManager = $this->app->make(CacheManager::class);

        return new OctaneFlushListener($cacheManager, new RuntimeContext);
    }

    /**
     * Build a QueueFlushSubscriber backed by the wired CacheManager singleton.
     *
     * @return \SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber
     */
    private function queueSubscriber(): QueueFlushSubscriber
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $cacheManager */
        $cacheManager = $this->app->make(CacheManager::class);

        return new QueueFlushSubscriber($cacheManager, new RuntimeContext);
    }
}
