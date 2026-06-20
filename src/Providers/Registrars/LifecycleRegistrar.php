<?php

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;

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
        $this->reportOffStateDiagnostic();
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

    /**
     * Emit a boot-time informational log when a serving runtime is detected but
     * the corresponding lifecycle flush is opted-out, so the off state is
     * observable rather than silent.
     *
     * Under php-fpm both runtime checks return false and nothing is logged.
     *
     * @return void
     */
    private function reportOffStateDiagnostic(): void
    {
        $runtime = app(RuntimeContext::class);

        if ($runtime->isServingUnderOctane() && !(bool) Config::get('api-toolkit.lifecycle.octane')) {
            Log::info('API Toolkit: serving under Octane but the lifecycle cache flush is disabled (API_TOOLKIT_LIFECYCLE_OCTANE=false); cross-request metadata may go stale.');
        }

        if ($runtime->isServingAsQueueWorker() && !(bool) Config::get('api-toolkit.lifecycle.queue')) {
            Log::info('API Toolkit: serving as a queue worker but the lifecycle cache flush is disabled (API_TOOLKIT_LIFECYCLE_QUEUE=false); cross-request metadata may go stale.');
        }
    }
}
