<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Contracts\OperationTerminated;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * Integration tests for the LifecycleRegistrar.
 *
 * The lifecycle configuration permutations are pinned by the ApiServiceProvider
 * integration suite; this test proves the registrar registers its surface when
 * invoked directly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LifecycleRegistrar::class)]
final class LifecycleRegistrarTest extends TestCase
{
    /** @var bool Whether LARAVEL_OCTANE was set before each test. */
    private bool $octaneWasSet;

    /**
     * Capture the initial LARAVEL_OCTANE state.
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
     * Test that the lifecycle subscribers are registered when the registrar is
     * invoked with the queue lifecycle enabled.
     *
     * @return void
     */
    public function testRegisterBindsLifecycleSubscribers(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.lifecycle.queue', true);

        (new LifecycleRegistrar)->register();

        self::assertTrue($this->hasSubscriberListener(RequestHandled::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class));
    }

    /**
     * Test that the Octane flush listener is wired when the lifecycle Octane
     * flush is enabled and the Octane marker interface is available.
     *
     * @return void
     */
    public function testOctaneFlushListenerRegisteredWhenInterfacePresentAndEnabled(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.octane', true);
        $config->set('api-toolkit.lifecycle.queue', false);

        // Reset the dispatcher so Octane's own OperationTerminated listeners do
        // not mask whether the registrar wires the toolkit's listener.
        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertTrue($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the Octane flush listener is not wired when the Octane marker
     * interface is absent, even with the lifecycle Octane flush enabled.
     *
     * @return void
     */
    public function testOctaneFlushListenerNotRegisteredWhenInterfaceAbsent(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.octane', true);
        $config->set('api-toolkit.lifecycle.queue', false);

        // Simulate laravel/octane being absent so the interface_exists guard
        // short-circuits before the listener binds.
        FunctionOverrides::set('interface_exists', static fn (string $interface): bool => $interface !== OperationTerminated::class && \interface_exists($interface));

        // Reset the dispatcher so Octane's own OperationTerminated listeners do
        // not mask the registrar short-circuiting.
        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertFalse($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that an off-state diagnostic is logged when serving under Octane but
     * the lifecycle flush is opted-out.
     *
     * @return void
     */
    public function testOffStateDiagnosticLogsWhenServingUnderOctaneButFlushOptedOut(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.octane', false);

        $expected = 'API Toolkit: serving under Octane but the lifecycle cache flush is disabled'
            . ' (API_TOOLKIT_LIFECYCLE_OCTANE=false); cross-request metadata may go stale'
            . ' and field-keyed metadata memos can grow unbounded per worker (memory tradeoff).';

        Log::shouldReceive('info')->once()->with(\Mockery::on(
            fn (string $message): bool => $message === $expected,
        ));

        (new LifecycleRegistrar)->register();
    }

    /**
     * Test that an off-state diagnostic is logged when serving as a queue
     * worker but the lifecycle flush is opted-out.
     *
     * @return void
     */
    public function testOffStateDiagnosticLogsWhenServingAsQueueWorkerButFlushOptedOut(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database.driver', 'database');
        $config->set('api-toolkit.lifecycle.queue', false);

        Log::shouldReceive('info')->once()->with(\Mockery::on(
            fn (string $message): bool => str_contains($message, 'queue worker') && str_contains($message, 'API_TOOLKIT_LIFECYCLE_QUEUE'),
        ));

        (new LifecycleRegistrar)->register();
    }

    /**
     * Test that no off-state diagnostic is logged under php-fpm (no serving
     * runtime detected).
     *
     * @return void
     */
    public function testOffStateDiagnosticIsSilentUnderPhpFpm(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('queue.default', 'sync');
        $config->set('api-toolkit.lifecycle.octane', false);
        $config->set('api-toolkit.lifecycle.queue', false);

        Log::shouldReceive('info')->never();

        (new LifecycleRegistrar)->register();
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
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
        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

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
}
