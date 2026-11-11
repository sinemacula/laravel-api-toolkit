<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * Integration tests that the shipped default config engages the flush.
 *
 * No lifecycle config is set here, so the merged package default is what is
 * read.
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
    use InteractsWithNonPublicMembers;

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
     * Test that the shipped defaults are on and engage the Octane flush.
     *
     * @return void
     */
    public function testShippedDefaultEngagesOctaneFlushOnServingRuntime(): void
    {
        self::assertTrue((bool) config('api-toolkit.lifecycle.octane'));
        self::assertTrue((bool) config('api-toolkit.lifecycle.queue'));

        $_SERVER['LARAVEL_OCTANE'] = 1;

        Event::fake();

        $key = 'integration:default-engagement-octane';

        $this->writer()->rememberMetadataForever($key, static fn () => 'value');
        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['FakeResource' => 'compiled']);

        $this->octaneListener()->handle(new \stdClass);

        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        self::assertSame('value', Cache::memo()->get($this->metadataStorageKey($key))); // @phpstan-ignore method.notFound
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that the shipped queue default engages the flush on a real worker.
     *
     * @return void
     */
    public function testShippedDefaultEngagesQueueFlushOnServingRuntime(): void
    {
        self::assertTrue((bool) config('api-toolkit.lifecycle.queue'));

        // A non-sync connection signals a real worker
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();

        $key = 'integration:default-engagement-queue';

        $this->writer()->rememberMetadataForever($key, static fn () => 'value');
        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['FakeResource' => 'compiled']);

        $event = new JobProcessed('database', self::createStub(Job::class));
        $this->queueSubscriber()->handleFlush($event);

        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        self::assertSame('value', Cache::memo()->get($this->metadataStorageKey($key))); // @phpstan-ignore method.notFound
        Event::assertDispatched(CacheFlushed::class);
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
