<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Contracts\OperationTerminated;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * Integration tests for the LifecycleRegistrar.
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
     * Test that the lifecycle subscribers register with the queue enabled.
     *
     * @return void
     */
    public function testRegisterBindsLifecycleSubscribers(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.lifecycle.queue', true);

        (new LifecycleRegistrar)->register();

        self::assertTrue($this->hasSubscriberListener(RequestHandled::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class));
    }

    /**
     * Test that the Octane flush listener is wired when enabled and available.
     *
     * @return void
     */
    public function testOctaneFlushListenerRegisteredWhenInterfacePresentAndEnabled(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.octane', true);
        $config->set('api-toolkit.lifecycle.queue', false);

        // Reset the dispatcher so Octane's own listeners cannot mask the result
        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertTrue($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the Octane flush listener is not wired without Octane.
     *
     * @return void
     */
    public function testOctaneFlushListenerNotRegisteredWhenInterfaceAbsent(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.octane', true);
        $config->set('api-toolkit.lifecycle.queue', false);

        // Simulate laravel/octane being absent
        FunctionOverrides::set(
            'interface_exists',
            static fn (mixed ...$arguments): bool => is_string($arguments[0]) && $arguments[0] !== OperationTerminated::class && \interface_exists($arguments[0]),
        );

        // Reset the dispatcher so Octane's own listeners cannot mask the result
        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertFalse($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the migration invalidation listener is wired when enabled.
     *
     * @return void
     */
    public function testMigrationInvalidationListenerRegisteredWhenEnabled(): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.migrations', true);

        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertTrue($events->hasListeners(MigrationsEnded::class));
    }

    /**
     * Test that the migration listener is wired when the gate is unpublished.
     *
     * A config published before the gate existed lacks the key.
     *
     * @return void
     */
    public function testMigrationInvalidationListenerRegisteredWhenTheGateIsUnpublished(): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle', ['octane' => true, 'queue' => true]);

        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertTrue($events->hasListeners(MigrationsEnded::class));
    }

    /**
     * Test that the migration invalidation listener is not wired when disabled.
     *
     * @return void
     */
    public function testMigrationInvalidationListenerNotRegisteredWhenDisabled(): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.migrations', false);

        Event::swap(new Dispatcher($this->getApplication()));

        (new LifecycleRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertFalse($events->hasListeners(MigrationsEnded::class));
    }

    /**
     * Test that resolving the migrator binds the default cache store.
     *
     * The store must follow the default connection before the migrator swaps
     * it.
     *
     * @return void
     */
    public function testResolvingTheMigratorBindsTheDefaultCacheStore(): void
    {
        (new LifecycleRegistrar)->register();

        $this->useDatabaseCacheStoreOnTheDefaultConnection();

        $this->getApplication()->forgetInstance('migrator');
        $this->getApplication()->make('migrator');

        DB::setDefaultConnection('secondary');

        self::assertSame('testing', $this->defaultCacheStoreConnection());
    }

    /**
     * Test that an already-resolved migrator gets the store bound at once.
     *
     * @return void
     */
    public function testAlreadyResolvedMigratorBindsTheDefaultCacheStore(): void
    {
        $this->getApplication()->make('migrator');

        $this->useDatabaseCacheStoreOnTheDefaultConnection();

        (new LifecycleRegistrar)->register();

        DB::setDefaultConnection('secondary');

        self::assertSame('testing', $this->defaultCacheStoreConnection());
    }

    /**
     * Test that the cache store resolves lazily until the migrator is resolved.
     *
     * @return void
     */
    public function testUnresolvedMigratorLeavesTheCacheStoreUnbound(): void
    {
        $this->getApplication()->offsetUnset('migrator');

        $this->useDatabaseCacheStoreOnTheDefaultConnection();

        (new LifecycleRegistrar)->register();

        DB::setDefaultConnection('secondary');

        self::assertSame('secondary', $this->defaultCacheStoreConnection());
    }

    /**
     * Test that the cache store resolves lazily when the gate is off.
     *
     * @return void
     */
    #[DefineEnvironment('disableMigrationInvalidation')]
    public function testMigratorDoesNotBindTheCacheStoreWhenDisabled(): void
    {
        $this->getApplication()->make('migrator');

        $this->useDatabaseCacheStoreOnTheDefaultConnection();

        (new LifecycleRegistrar)->register();

        $this->getApplication()->forgetInstance('migrator');
        $this->getApplication()->make('migrator');

        DB::setDefaultConnection('secondary');

        self::assertSame('secondary', $this->defaultCacheStoreConnection());
    }

    /**
     * Test that an unusable cache store does not stop the migrator resolving.
     *
     * @return void
     */
    public function testUnusableCacheStoreDoesNotStopTheMigratorResolving(): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('cache.stores.unusable', ['driver' => 'unsupported']);
        $config->set('cache.default', 'unusable');

        (new LifecycleRegistrar)->register();

        $this->getApplication()->forgetInstance('migrator');

        self::assertIsObject($this->getApplication()->make('migrator'));
    }

    /**
     * Test that a cache store failing with an Error does not stop the migrator.
     *
     * A driver whose dependency is missing fails as an Error, which must not
     * break every migration command and the command list.
     *
     * @return void
     */
    public function testCacheStoreFailingWithAnErrorDoesNotStopTheMigratorResolving(): void
    {
        Cache::extend('broken', static function (): never {
            throw new \Error('The cache driver dependency is not installed.');
        });

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('cache.stores.broken', ['driver' => 'broken']);
        $config->set('cache.default', 'broken');

        (new LifecycleRegistrar)->register();

        $this->getApplication()->forgetInstance('migrator');

        self::assertIsObject($this->getApplication()->make('migrator'));
    }

    /**
     * Test that an off-state diagnostic is logged under Octane when opted out.
     *
     * @return void
     */
    public function testOffStateDiagnosticLogsWhenServingUnderOctaneButFlushOptedOut(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.octane', false);

        $expected = 'API Toolkit: serving under Octane but the lifecycle cache flush is disabled'
            . ' (API_TOOLKIT_LIFECYCLE_OCTANE=false); in-process metadata memos grow unbounded'
            . ' per worker and the worker never re-reads the metadata generation, so an'
            . ' invalidation made elsewhere is not picked up.';

        Log::shouldReceive('info')->once()->with(\Mockery::on(
            fn (string $message): bool => $message === $expected,
        ));

        (new LifecycleRegistrar)->register();
    }

    /**
     * Test that an off-state diagnostic is logged on a worker when opted out.
     *
     * @return void
     */
    public function testOffStateDiagnosticLogsWhenServingAsQueueWorkerButFlushOptedOut(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        $argv            = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'queue:work'];

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('api-toolkit.lifecycle.queue', false);

        $expected = 'API Toolkit: serving as a queue worker but the lifecycle cache flush is disabled'
            . ' (API_TOOLKIT_LIFECYCLE_QUEUE=false); in-process metadata memos grow unbounded'
            . ' per worker and the worker never re-reads the metadata generation, so an'
            . ' invalidation made elsewhere is not picked up.';

        Log::shouldReceive('info')->once()->with(\Mockery::on(
            fn (string $message): bool => $message === $expected,
        ));

        try {
            (new LifecycleRegistrar)->register();
        } finally {
            if ($argv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $argv;
            }
        }
    }

    /**
     * Test that no off-state diagnostic is logged under php-fpm.
     *
     * A web request is not a worker, even when the default queue driver is
     * non-sync.
     *
     * @return void
     */
    public function testOffStateDiagnosticIsSilentUnderPhpFpm(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        $argv            = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'route:list'];

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database.driver', 'database');
        $config->set('api-toolkit.lifecycle.octane', false);
        $config->set('api-toolkit.lifecycle.queue', false);

        Log::shouldReceive('info')->never();

        try {
            (new LifecycleRegistrar)->register();
        } finally {
            if ($argv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $argv;
            }
        }
    }

    /**
     * Switch the migrations lifecycle gate off before the application boots.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function disableMigrationInvalidation(mixed $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('api-toolkit.lifecycle.migrations', false);
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
     * Determine whether the event has a listener from the given subscriber.
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

    /**
     * Point the default cache store at a connection-less database store.
     *
     * A second connection is defined for the default to be swapped to.
     *
     * @return void
     */
    private function useDatabaseCacheStoreOnTheDefaultConnection(): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->getApplication()->make('config');
        $config->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('cache.stores.default_connection', ['driver' => 'database', 'table' => 'cache', 'connection' => null]);
        $config->set('cache.default', 'default_connection');
    }

    /**
     * Return the name of the connection the default cache store is bound to.
     *
     * @return string|null
     */
    private function defaultCacheStoreConnection(): ?string
    {
        $store = Cache::store()->getStore();

        self::assertInstanceOf(DatabaseStore::class, $store);

        $connection = $store->getConnection();

        self::assertInstanceOf(Connection::class, $connection);

        return $connection->getName();
    }
}
