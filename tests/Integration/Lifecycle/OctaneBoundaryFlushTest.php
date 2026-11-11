<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestTerminated;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * End-to-end tests that the wired OperationTerminated event flushes correctly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
#[CoversClass(LifecycleRegistrar::class)]
#[CoversClass(MetadataCacheWriter::class)]
#[CoversClass(OctaneFlushListener::class)]
#[CoversClass(RuntimeContext::class)]
final class OctaneBoundaryFlushTest extends TestCase
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
     * Test that dispatching OperationTerminated resets in-process state only.
     *
     * @return void
     */
    public function testDispatchingOperationTerminatedResetsInProcessStateOnly(): void
    {
        self::assertTrue((bool) config('api-toolkit.lifecycle.octane'));
        self::assertTrue($this->events()->hasListeners(OperationTerminated::class));

        // Simulate a serving Octane worker so the runtime gate opens.
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $toolkitKey    = 'integration:octane-boundary-toolkit';
        $nonToolkitKey = 'app:octane-boundary-keep';

        $this->writer()->rememberMetadataForever($toolkitKey, static fn () => 'toolkit-value');
        Cache::memo()->rememberForever($nonToolkitKey, static fn () => 'keep-me'); // @phpstan-ignore method.notFound

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['FakeResource' => 'compiled']);

        $this->events()->dispatch($this->operationTerminated());

        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        self::assertSame('toolkit-value', Cache::store()->get($this->metadataStorageKey($toolkitKey)));
        self::assertSame('keep-me', Cache::store()->get($nonToolkitKey));
    }

    /**
     * Test that forgetting scoped instances discards the memoised store.
     *
     * Octane and the queue worker do this at every boundary, so the toolkit
     * keeps no memo of its own.
     *
     * @return void
     */
    public function testForgettingScopedInstancesDiscardsTheMemoisedStore(): void
    {
        assert($this->app !== null);

        $before = Cache::memo();

        $this->app->forgetScopedInstances();

        self::assertNotSame($before, Cache::memo());
    }

    /**
     * Build a genuine OperationTerminated event for the current application.
     *
     * @return \Laravel\Octane\Contracts\OperationTerminated
     */
    private function operationTerminated(): OperationTerminated
    {
        assert($this->app !== null);

        return new RequestTerminated(
            $this->app,
            $this->app,
            Request::create('/'),
            new Response,
        );
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
     * Resolve the event dispatcher.
     *
     * @return \Illuminate\Contracts\Events\Dispatcher
     */
    private function events(): Dispatcher
    {
        assert($this->app !== null);

        /** @var \Illuminate\Events\Dispatcher */
        return $this->app->make('events');
    }
}
