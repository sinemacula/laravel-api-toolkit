<?php

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;

/**
 * Registers the toolkit lifecycle listeners.
 *
 * Subscribes the write pool flush subscriber, the Octane flush listener, and
 * the queue flush subscriber to their lifecycle events, honouring the
 * configured gates.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LifecycleRegistrar
{
    /**
     * Register the toolkit lifecycle listeners.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerWritePoolFlushSubscriber();
        $this->registerOctaneFlushListener();
        $this->registerQueueFlushSubscriber();
    }

    /**
     * Subscribe the write pool flush subscriber to lifecycle events.
     *
     * @return void
     */
    private function registerWritePoolFlushSubscriber(): void
    {
        Event::subscribe(WritePoolFlushSubscriber::class);
    }

    /**
     * Register the Octane flush listener if configured and Octane is
     * installed.
     *
     * @return void
     */
    private function registerOctaneFlushListener(): void
    {
        if (!(bool) Config::get('api-toolkit.lifecycle.octane')) {
            return;
        }

        if (!class_exists(\Laravel\Octane\Events\OperationTerminated::class)) {
            return;
        }

        Event::listen(\Laravel\Octane\Events\OperationTerminated::class, OctaneFlushListener::class);
    }

    /**
     * Register the queue flush subscriber if configured.
     *
     * @return void
     */
    private function registerQueueFlushSubscriber(): void
    {
        if (!(bool) Config::get('api-toolkit.lifecycle.queue')) {
            return;
        }

        Event::subscribe(QueueFlushSubscriber::class);
    }
}
