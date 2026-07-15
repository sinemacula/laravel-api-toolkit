<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for the maintenance-mode middleware through the HTTP kernel.
 *
 * Activates maintenance mode and dispatches real requests: a guarded route
 * renders the toolkit service-unavailable envelope rather than a raw HTML error
 * page, while a route named in the bypass list is served as normal - which only
 * holds because the swap removes the framework default so the toolkit except
 * list is authoritative.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PreventRequestsDuringMaintenance::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class MaintenanceModeTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with maintenance-guarded routes and a bypass list.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.maintenance_mode.except', ['api/health']);

        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/api/data', static fn (): array => ['ok' => true]);
        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/api/health', static fn (): array => ['ok' => true]);
    }

    /**
     * Test that a guarded route renders the service-unavailable envelope
     * while maintenance mode is active.
     *
     * @return void
     */
    public function testGuardedRouteRendersServiceUnavailableEnvelope(): void
    {
        $response = $this->duringMaintenance(fn () => $this->getJson('/api/data'));

        $response->assertStatus(503);
        $response->assertJsonPath('error.status', 503);
        $response->assertJsonPath('error.code', 10200);
    }

    /**
     * Test that a bypass-list route is served as normal during maintenance
     * mode.
     *
     * @return void
     */
    public function testBypassListIsServedDuringMaintenance(): void
    {
        $response = $this->duringMaintenance(fn () => $this->getJson('/api/health'));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    /**
     * Activate maintenance mode, run the callback, and deactivate afterwards.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function duringMaintenance(\Closure $callback): mixed
    {
        assert($this->app !== null);

        $this->app->maintenanceMode()->activate([
            'redirect' => null,
            'retry'    => 60,
            'refresh'  => null,
            'secret'   => null,
            'status'   => 503,
            'template' => null,
        ]);

        try {
            return $callback();
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }
}
