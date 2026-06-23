<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * Proves that the SHIPPED DEFAULT config engages the lifecycle flush.
 *
 * No lifecycle config keys are set in this file - the tests read the merged
 * package default directly to pin that the shipped default is true and that
 * the flush engages under a detected serving runtime. This kills the
 * default-value mutation (a mutant flipping the default back to false must
 * fail these assertions).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
#[CoversClass(OctaneFlushListener::class)]
#[CoversClass(QueueFlushSubscriber::class)]
final class LifecycleDefaultEngagementTest extends TestCase
{
    /** @var bool Whether LARAVEL_OCTANE was set before each test. */
    private bool $octaneWasSet;

    /**
     * Capture the initial LARAVEL_OCTANE server state.
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
     * Restore LARAVEL_OCTANE to its pre-test state after each test.
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
     * Test that the shipped default for lifecycle.octane and lifecycle.queue
     * is true and that the Octane flush engages on a detected serving runtime.
     *
     * Validates TAC-09-01 and TAC-09-02: with no config override the merged
     * default is true, and invoking the Octane boundary on a serving runtime
     * clears the registered toolkit key.
     *
     * @return void
     */
    public function testShippedDefaultEngagesOctaneFlushOnServingRuntime(): void
    {
        // Assert the shipped defaults are on - kills the default-value
        // mutation.
        static::assertTrue((bool) config('api-toolkit.lifecycle.octane'));
        static::assertTrue((bool) config('api-toolkit.lifecycle.queue'));

        // Arrange: simulate a serving Octane runtime.
        $_SERVER['LARAVEL_OCTANE'] = 1;

        Event::fake();

        $key = 'integration:default-engagement-octane';

        // Write through the writer so the key is registered in the registry.
        $this->writer()->rememberMetadataForever($key, static fn () => 'value');
        static::assertSame('value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Act: invoke the Octane boundary with the shipped default config.
        $this->octaneListener()->handle(new \stdClass);

        // Assert: the toolkit key was cleared (flush engaged on the default).
        static::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that the shipped queue default engages the flush on a non-sync
     * worker boundary.
     *
     * Validates TAC-09-01: the shipped queue default is true, and the flush
     * engages when a real (non-sync) queue connection processes a job.
     *
     * @return void
     */
    public function testShippedDefaultEngagesQueueFlushOnServingRuntime(): void
    {
        // Assert the shipped queue default is on.
        static::assertTrue((bool) config('api-toolkit.lifecycle.queue'));

        // Arrange: configure a non-sync connection to signal a real worker.
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();

        $key = 'integration:default-engagement-queue';

        // Write through the writer so the key is registered in the registry.
        $this->writer()->rememberMetadataForever($key, static fn () => 'value');
        static::assertSame('value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Act: invoke the queue boundary with the shipped default config.
        $event = new JobProcessed('database', static::createStub(Job::class));
        $this->queueSubscriber()->handleFlush($event);

        // Assert: the toolkit key was cleared (flush engaged on the default).
        static::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
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
