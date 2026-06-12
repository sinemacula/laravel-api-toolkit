<?php

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use Tests\TestCase;

/**
 * Integration tests for the LifecycleRegistrar.
 *
 * The lifecycle configuration permutations are pinned by the
 * ApiServiceProvider integration suite; this test proves the registrar
 * registers its surface when invoked directly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LifecycleRegistrar::class)]
class LifecycleRegistrarTest extends TestCase
{
    /**
     * Test that the lifecycle subscribers are registered when the registrar
     * is invoked with the queue lifecycle enabled.
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

        static::assertTrue($this->eventHasSubscriberListener(RequestHandled::class, WritePoolFlushSubscriber::class));
        static::assertTrue($this->eventHasSubscriberListener(\Illuminate\Queue\Events\JobProcessed::class, QueueFlushSubscriber::class));
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
     * Determine whether the given event has a listener belonging to the
     * given subscriber class.
     *
     * @param  class-string  $event
     * @param  class-string  $subscriber
     * @return bool
     */
    private function eventHasSubscriberListener(string $event, string $subscriber): bool
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
