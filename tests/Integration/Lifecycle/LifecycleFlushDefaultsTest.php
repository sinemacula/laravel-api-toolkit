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
 * Integration tests for metadata across Octane and queue lifecycle boundaries.
 *
 * Each test sets config and $_SERVER explicitly, independent of the shipped
 * defaults.
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
     * Test that shared metadata survives an Octane boundary until invalidated.
     *
     * @return void
     */
    public function testOctaneBoundaryKeepsSharedMetadataUntilInvalidated(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $key = 'integration:octane-staleness-test';

        $this->remember($key, 'old-shape');

        $this->octaneListener()->handle(new \stdClass);

        self::assertSame('old-shape', $this->remember($key, 'new-shape'));

        $this->cacheManager()->invalidateMetadata();

        self::assertSame('new-shape', $this->remember($key, 'new-shape'));
    }

    /**
     * Test that shared metadata survives a queue boundary until invalidated.
     *
     * @return void
     */
    public function testQueueBoundaryKeepsSharedMetadataUntilInvalidated(): void
    {
        Config::set('queue.connections.database.driver', 'database');
        Config::set('api-toolkit.lifecycle.queue', true);

        Event::fake();

        $key = 'integration:queue-staleness-test';

        $this->remember($key, 'old-shape');

        $event = new JobProcessed('database', self::createStub(Job::class));
        $this->queueSubscriber()->handleFlush($event);

        self::assertSame('old-shape', $this->remember($key, 'new-shape'));

        $this->cacheManager()->invalidateMetadata();

        self::assertSame('new-shape', $this->remember($key, 'new-shape'));
    }

    /**
     * Test that the Octane listener does not flush under php-fpm.
     *
     * @return void
     */
    public function testPhpFpmRequestPerformsNoFlush(): void
    {
        // No LARAVEL_OCTANE signal, as under php-fpm
        unset($_SERVER['LARAVEL_OCTANE']);
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $key    = 'integration:php-fpm-no-flush-test';
        $writer = $this->writer();

        $writer->rememberMetadataForever($key, static fn () => 'value');
        self::assertSame('value', Cache::memo()->get($this->metadataStorageKey($key))); // @phpstan-ignore method.notFound

        $this->octaneListener()->handle(new \stdClass);

        self::assertSame('value', Cache::memo()->get($this->metadataStorageKey($key))); // @phpstan-ignore method.notFound
    }

    /**
     * Test that the opt-out flag stops the lifecycle flush being subscribed.
     *
     * The queue gate is the oracle because the Octane path also gates on
     * interface_exists().
     *
     * @return void
     */
    public function testOptOutDisablesLifecycleFlushSubscription(): void
    {
        // Reset the dispatcher so boot-time wiring does not skew the baseline
        Event::swap(new Dispatcher($this->app));

        Config::set('api-toolkit.lifecycle.queue', false);

        (new LifecycleRegistrar)->register();

        self::assertFalse(
            $this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class),
            'QueueFlushSubscriber must not be wired when lifecycle.queue is false',
        );

        // Control: the flag on wires it, so the opt-out is not vacuous
        Config::set('api-toolkit.lifecycle.queue', true);

        (new LifecycleRegistrar)->register();

        self::assertTrue(
            $this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class),
            'QueueFlushSubscriber must be wired when lifecycle.queue is true',
        );
    }

    /**
     * Test that a boundary leaves toolkit and non-toolkit store keys untouched.
     *
     * @return void
     */
    public function testSharedStoreKeysSurviveBoundary(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;
        Config::set('api-toolkit.lifecycle.octane', true);

        Event::fake();

        $toolkitKey    = 'integration:toolkit-key';
        $nonToolkitKey = 'app:user-prefs';

        $this->writer()->rememberMetadataForever($toolkitKey, static fn () => 'toolkit-value');

        Cache::memo()->rememberForever($nonToolkitKey, static fn () => 'keep-me'); // @phpstan-ignore method.notFound

        $this->octaneListener()->handle(new \stdClass);

        self::assertSame('toolkit-value', Cache::store()->get($this->metadataStorageKey($toolkitKey)));
        self::assertSame('keep-me', Cache::store()->get($nonToolkitKey));
    }

    /**
     * Determine whether the event has a listener from the given subscriber.
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
     * Remember the value through the wired writer, returning what is served.
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
