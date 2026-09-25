<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
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

/**
 * Integration harness: metadata across Octane and queue lifecycle boundaries.
 *
 * Proves that shared metadata survives a lifecycle boundary and is rebuilt only
 * once it is invalidated, that php-fpm does not engage, that opt-out is
 * honoured, and that nothing on the shared store is cleared by a boundary.
 *
 * Every test sets the relevant config and $_SERVER state explicitly, so the
 * mechanism is validated independently of the shipped defaults.
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
     * Test that shared metadata survives an Octane boundary, so request 2 is
     * served what request 1 stored, and that only an explicit invalidation
     * makes it rebuild.
     *
     * @return void
     */
    public function testOctaneBoundaryKeepsSharedMetadataUntilInvalidated(): void
    {
        // Arrange
        $_SERVER['LARAVEL_OCTANE'] = 1;
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $key = 'integration:octane-staleness-test';

        // Request 1: the old shape is stored.
        $this->remember($key, 'old-shape');

        // Boundary: simulate end-of-request Octane flush.
        $this->octaneListener()->handle(new \stdClass);

        // Request 2: the stored shape is served rather than rebuilt.
        self::assertSame('old-shape', $this->remember($key, 'new-shape'));

        // Deploy: an explicit invalidation retires it, and the next read
        // rebuilds.
        $this->cacheManager()->invalidateMetadata();

        self::assertSame('new-shape', $this->remember($key, 'new-shape'));
    }

    /**
     * Test that shared metadata survives a queue job boundary, so job 2 is
     * served what job 1 stored, and that only an explicit invalidation makes it
     * rebuild.
     *
     * @return void
     */
    public function testQueueBoundaryKeepsSharedMetadataUntilInvalidated(): void
    {
        // Arrange
        Config::set('queue.connections.database.driver', 'database');
        Config::set('api-toolkit.lifecycle.queue', true);

        Event::fake();

        $key = 'integration:queue-staleness-test';

        // Job 1: the old shape is stored.
        $this->remember($key, 'old-shape');

        // Boundary: simulate end-of-job queue flush.
        $event = new JobProcessed('database', self::createStub(Job::class));
        $this->queueSubscriber()->handleFlush($event);

        // Job 2: the stored shape is served rather than rebuilt.
        self::assertSame('old-shape', $this->remember($key, 'new-shape'));

        // Deploy: an explicit invalidation retires it, and the next read
        // rebuilds.
        $this->cacheManager()->invalidateMetadata();

        self::assertSame('new-shape', $this->remember($key, 'new-shape'));
    }

    /**
     * Test that under php-fpm (no LARAVEL_OCTANE signal), the Octane listener
     * does not perform a flush even when the config flag is on.
     *
     * The runtime gate, not the config flag, prevents flush engagement outside
     * a long-lived Octane worker.
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
        self::assertSame('value', Cache::memo()->get($this->metadataStorageKey($key))); // @phpstan-ignore method.notFound

        // Act: invoke the boundary under php-fpm conditions.
        $this->octaneListener()->handle(new \stdClass);

        // Assert: the key must survive because no flush ran.
        self::assertSame('value', Cache::memo()->get($this->metadataStorageKey($key))); // @phpstan-ignore method.notFound
    }

    /**
     * Test that the opt-out flag prevents the lifecycle flush from being wired
     * to its boundary at all.
     *
     * With the lifecycle flag off, LifecycleRegistrar does not subscribe the
     * flush, so no boundary can fire it. The queue path is the representative
     * opt-out oracle - the Octane path additionally gates on
     * interface_exists(OperationTerminated), so the queue gate is the one that
     * can be isolated cleanly. The enabled control proves the assertion tracks
     * the flag rather than passing vacuously.
     *
     * @return void
     */
    public function testOptOutDisablesLifecycleFlushSubscription(): void
    {
        // Reset the dispatcher so the boot-time wiring (now default-on) does
        // not pollute the baseline being tested.
        Event::swap(new Dispatcher($this->app));

        // Opt-out: the flag is off, so the registrar must not wire the
        // subscriber.
        Config::set('api-toolkit.lifecycle.queue', false);

        (new LifecycleRegistrar)->register();

        self::assertFalse(
            $this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class),
            'QueueFlushSubscriber must not be wired when lifecycle.queue is false',
        );

        // Control: with the flag on, the registrar wires it - proving the gate,
        // not an always-false assertion, drives the opt-out behaviour.
        Config::set('api-toolkit.lifecycle.queue', true);

        (new LifecycleRegistrar)->register();

        self::assertTrue(
            $this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class),
            'QueueFlushSubscriber must be wired when lifecycle.queue is true',
        );
    }

    /**
     * Test that a boundary leaves the shared store untouched: the toolkit's own
     * metadata and a non-toolkit key written beside it both survive.
     *
     * @return void
     */
    public function testSharedStoreKeysSurviveBoundary(): void
    {
        // Arrange
        $_SERVER['LARAVEL_OCTANE'] = 1;
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $toolkitKey    = 'integration:toolkit-key';
        $nonToolkitKey = 'app:user-prefs';

        $this->writer()->rememberMetadataForever($toolkitKey, static fn () => 'toolkit-value');

        Cache::memo()->rememberForever($nonToolkitKey, static fn () => 'keep-me'); // @phpstan-ignore method.notFound

        // Act: invoke the Octane boundary.
        $this->octaneListener()->handle(new \stdClass);

        // Assert: both keys survive in the underlying store.
        self::assertSame('toolkit-value', Cache::store()->get($this->metadataStorageKey($toolkitKey)));
        self::assertSame('keep-me', Cache::store()->get($nonToolkitKey));
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
     * Remember the given value under the key through the wired writer,
     * returning whatever the store serves.
     *
     * @param  string  $key
     * @param  string  $value
     * @return mixed
     */
    private function remember(string $key, string $value): mixed
    {
        return $this->writer()->rememberMetadataForever($key, static fn (): string => $value);
    }

    /**
     * Resolve the wired CacheManager singleton.
     *
     * @return \SineMacula\ApiToolkit\Cache\CacheManager
     */
    private function cacheManager(): CacheManager
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Cache\CacheManager */
        return $this->app->make(CacheManager::class);
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
