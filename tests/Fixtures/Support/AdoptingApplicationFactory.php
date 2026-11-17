<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use Illuminate\Foundation\Application;
use Laravel\Octane\ApplicationFactory;

/**
 * Octane application factory that adopts an already booted application as the
 * worker's base instead of bootstrapping a new one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AdoptingApplicationFactory extends ApplicationFactory
{
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
     * Adopt the booted application and warm its services.
     *
     * @param  array<array-key, mixed>  $initialInstances
     * @return \Illuminate\Foundation\Application
     */
    #[\Override]
    public function createApplication(array $initialInstances = []): Application
    {
        foreach ($initialInstances as $key => $value) {
            $this->app->instance((string) $key, $value);
        }

        return $this->warm($this->app);
    }
}
