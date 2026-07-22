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
use Tests\TestCase;

/**
 * End-to-end proof that the wired OperationTerminated event flushes toolkit
 * metadata.
 *
 * Every other Octane-flush test invokes the listener via a hand-built handle()
 * call. This file dispatches the real OperationTerminated event through the
 * container's dispatcher under the shipped default config, proving that the
 * boot-time listener wiring - not just the listener in isolation - clears the
 * toolkit metadata memo while a non-toolkit key on the shared store survives.
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
     * wired dispatcher flushes toolkit metadata while a non-toolkit key
     * survives.
     *
     * The boot-time wiring subscribes the OctaneFlushListener under the shipped
     * default config. Dispatching the genuine event - rather than calling
     * handle() directly - proves the registration binds the correct event to
     * the correct listener, clearing the toolkit key while leaving a
     * non-toolkit key on the shared memo store untouched.
     *
     * @return void
     */
    public function testDispatchingOperationTerminatedFlushesToolkitMetadata(): void
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

        self::assertSame('toolkit-value', Cache::memo()->get($toolkitKey)); // @phpstan-ignore method.notFound
        self::assertSame('keep-me', Cache::memo()->get($nonToolkitKey)); // @phpstan-ignore method.notFound

        // Act: dispatch the real event through the wired dispatcher.
        $this->events()->dispatch($this->operationTerminated());

        // Assert: the toolkit key is flushed; the non-toolkit key survives.
        self::assertNull(Cache::memo()->get($toolkitKey)); // @phpstan-ignore method.notFound
        self::assertSame('keep-me', Cache::memo()->get($nonToolkitKey)); // @phpstan-ignore method.notFound
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

        /** @var \Illuminate\Contracts\Events\Dispatcher */
        return $this->app->make('events');
    }
}
