<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Contracts\OperationTerminated;
use SineMacula\ApiToolkit\Config\LifecycleSettings;
use SineMacula\ApiToolkit\Listeners\MigrationInvalidationListener;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;

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
     * The queue flush subscriber comes first, so a re-thrown write pool flush
     * failure at the end of a job attempt cannot skip the state reset.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerQueueFlushSubscriber();
        $this->registerWritePoolFlushSubscriber();
        $this->registerOctaneFlushListener();
        $this->registerMigrationInvalidationListener();
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
     * Register the Octane flush listener if Octane is installed.
     *
     * @return void
     */
    private function registerOctaneFlushListener(): void
    {
        if (!interface_exists(OperationTerminated::class)) {
            return;
        }

        Event::listen(OperationTerminated::class, OctaneFlushListener::class);
    }

    /**
     * Register the queue flush subscriber.
     *
     * @return void
     */
    private function registerQueueFlushSubscriber(): void
    {
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
        if (!LifecycleSettings::shouldInvalidateOnMigrations()) {
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
}
