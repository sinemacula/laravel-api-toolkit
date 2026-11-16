<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;

/**
 * Serves operations through a real Octane worker that adopts the test
 * application as its base and clones it into a sandbox for every request, task
 * and tick.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RunsOctaneWorker
{
    /**
     * Serve the given requests through an Octane worker.
     *
     * @param  \Illuminate\Http\Request  ...$requests
     * @return \Laravel\Octane\Testing\Fakes\FakeClient
     */
    protected function serveThroughOctane(Request ...$requests): FakeClient
    {
        $client = new FakeClient(array_values($requests));

        $this->runOctaneWorker($client, static fn (FakeWorker $worker) => $worker->run());

        return $client;
    }

    /**
     * Run the given tasks through an Octane worker.
     *
     * @param  \Closure(): mixed  ...$tasks
     * @return void
     */
    protected function runOctaneTasks(\Closure ...$tasks): void
    {
        $this->runOctaneWorker(new FakeClient(array_values($tasks)), static fn (FakeWorker $worker) => $worker->runTasks());
    }

    /**
     * Run the given number of ticks through an Octane worker.
     *
     * @param  int  $ticks
     * @return void
     */
    protected function runOctaneTicks(int $ticks): void
    {
        $this->runOctaneWorker(new FakeClient(range(1, $ticks)), static fn (FakeWorker $worker) => $worker->runTicks());
    }

    /**
     * Boot an Octane worker over the given client, run the operations, then
     * hand the base application back to the services the sandboxes held.
     *
     * @param  \Laravel\Octane\Testing\Fakes\FakeClient  $client
     * @param  \Closure(\Laravel\Octane\Testing\Fakes\FakeWorker): mixed  $operations
     * @return void
     */
    private function runOctaneWorker(FakeClient $client, \Closure $operations): void
    {
        $app = $this->app;

        assert($app instanceof Application);

        if (!$app->providerIsLoaded(OctaneServiceProvider::class)) {
            $this->registerOctane($app);
        }

        $worker = new FakeWorker(new class ($app) extends ApplicationFactory {
            /**
             * Create a new adopting application factory instance.
             *
             * @param  \Illuminate\Foundation\Application  $app
             * @return void
             */
            public function __construct(

                /** The booted application the worker adopts */
                private readonly Application $app,
            ) {
                parent::__construct($app->basePath());
            }

            /**
             * Adopt the booted application instead of bootstrapping a new one.
             *
             * @param  array<string, mixed>  $initialInstances
             * @return \Illuminate\Foundation\Application
             */
            #[\Override]
            public function createApplication(array $initialInstances = []): Application
            {
                foreach ($initialInstances as $key => $value) {
                    $this->app->instance($key, $value);
                }

                return $this->warm($this->app);
            }
        }, $client);

        $worker->boot();

        $operations($worker);

        // The managers still hold the last sandbox until the next operation
        $app->make('events')->dispatch(new TickReceived($app, $app));
    }

    /**
     * Register Octane and mark the process as served by it until the test ends.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    private function registerOctane(Application $app): void
    {
        $app->register(OctaneServiceProvider::class);

        // The worker warms the encrypter, which needs a key as in production
        Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $wasServing = $_SERVER['LARAVEL_OCTANE'] ?? null;

        $_SERVER['LARAVEL_OCTANE'] = 1;

        $this->beforeApplicationDestroyed(static function () use ($wasServing): void {
            if ($wasServing === null) {
                unset($_SERVER['LARAVEL_OCTANE']);
            } else {
                $_SERVER['LARAVEL_OCTANE'] = $wasServing;
            }
        });
    }
}
