<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Contracts\OperationTerminated;
use SineMacula\ApiToolkit\Listeners\MigrationInvalidationListener;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;

/**
 * Registers the toolkit lifecycle listeners.
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
        $this->registerMigrationInvalidationListener();
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
     * Register the Octane flush listener if configured and Octane is installed.
     *
     * @return void
     */
    private function registerOctaneFlushListener(): void
    {
        if (!(bool) Config::get('api-toolkit.lifecycle.octane')) {
            return;
        }

        if (!interface_exists(OperationTerminated::class)) {
            return;
        }

        Event::listen(OperationTerminated::class, OctaneFlushListener::class);
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
     * Register the migration invalidation listener if configured.
     *
     * The migrator swaps the default connection while it runs, so the cache
     * store is bound first to keep the invalidation in the serving store.
     *
     * @return void
     */
    private function registerMigrationInvalidationListener(): void
    {
        if (!(bool) Config::get('api-toolkit.lifecycle.migrations', true)) {
            return;
        }

        Event::listen(MigrationsEnded::class, MigrationInvalidationListener::class);

        $app = app();

        $app->afterResolving('migrator', $this->bindDefaultCacheStore(...));

        if (!$app->resolved('migrator')) {
            return;
        }

        $this->bindDefaultCacheStore();
    }

    /**
     * Resolve the default cache store so it is memoised against the default
     * database connection.
     *
     * @return void
     */
    private function bindDefaultCacheStore(): void
    {
        try {
            Cache::store();
        } catch (\Throwable) {
            // The listener reports an unusable store once the migrations end
        }
    }

    /**
     * Log at boot when a serving runtime is detected but its lifecycle flush is
     * disabled.
     *
     * @return void
     */
    private function reportOffStateDiagnostic(): void
    {
        $runtime = app(RuntimeContext::class);

        if ($runtime->isServingUnderOctane() && !(bool) Config::get('api-toolkit.lifecycle.octane')) {
            Log::info(
                'API Toolkit: serving under Octane but the lifecycle cache flush is disabled'
                . ' (API_TOOLKIT_LIFECYCLE_OCTANE=false); in-process metadata memos grow unbounded'
                . ' per worker and the worker never re-reads the metadata generation, so an'
                . ' invalidation made elsewhere is not picked up.',
            );
        }

        if (!$runtime->isServingAsQueueWorker() || (bool) Config::get('api-toolkit.lifecycle.queue')) {
            return;
        }

        Log::info(
            'API Toolkit: serving as a queue worker but the lifecycle cache flush is disabled'
            . ' (API_TOOLKIT_LIFECYCLE_QUEUE=false); in-process metadata memos grow unbounded'
            . ' per worker and the worker never re-reads the metadata generation, so an'
            . ' invalidation made elsewhere is not picked up.',
        );
    }
}
