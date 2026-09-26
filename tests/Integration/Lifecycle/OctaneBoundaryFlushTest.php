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
 * End-to-end proof that the wired OperationTerminated event resets the
 * toolkit's in-process state and leaves the shared store alone.
 *
 * Every other Octane-flush test invokes the listener via a hand-built handle()
 * call. This file dispatches the real OperationTerminated event through the
 * container's dispatcher under the shipped default config, proving that the
 * boot-time listener wiring - not just the listener in isolation - resets the
 * in-process memos while the toolkit metadata and a non-toolkit key on the
 * shared store both survive.
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
     * Test that dispatching the real OperationTerminated event through the
     * wired dispatcher resets in-process state while the shared store keeps
     * both the toolkit metadata and a non-toolkit key.
     *
     * The boot-time wiring subscribes the OctaneFlushListener under the shipped
     * default config. Dispatching the genuine event - rather than calling
     * handle() directly - proves the registration binds the correct event to
     * the correct listener.
     *
     * @return void
     */
    public function testDispatchingOperationTerminatedResetsInProcessStateOnly(): void
    {
        // The shipped default engages the Octane lifecycle flush, so the
        // listener must already be wired at boot.
        self::assertTrue((bool) config('api-toolkit.lifecycle.octane'));
        self::assertTrue($this->events()->hasListeners(OperationTerminated::class));

        // Simulate a serving Octane worker so the runtime gate opens.
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $toolkitKey    = 'integration:octane-boundary-toolkit';
        $nonToolkitKey = 'app:octane-boundary-keep';

        $this->writer()->rememberMetadataForever($toolkitKey, static fn () => 'toolkit-value');
        Cache::memo()->rememberForever($nonToolkitKey, static fn () => 'keep-me'); // @phpstan-ignore method.notFound

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['FakeResource' => 'compiled']);

        // Act: dispatch the real event through the wired dispatcher.
        $this->events()->dispatch($this->operationTerminated());

        // Assert: in-process state is reset; both stored keys survive.
        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        self::assertSame('toolkit-value', Cache::store()->get($this->metadataStorageKey($toolkitKey)));
        self::assertSame('keep-me', Cache::store()->get($nonToolkitKey));
    }

    /**
     * Test that the framework itself discards the memoised store repository
     * when scoped instances are forgotten, as Octane and the queue worker do at
     * every boundary, so the toolkit has no memo of its own to clear.
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
