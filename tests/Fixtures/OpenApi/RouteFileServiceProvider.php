<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Support\ServiceProvider;

/**
 * Fixture provider loading an application route file as a provider does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RouteFileServiceProvider extends ServiceProvider
{
    /**
     * Load the closure route file.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/closures.inc');
    }
}
