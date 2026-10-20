<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Support\ArrayMaintenanceMode;
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

        // Use an in-memory maintenance driver so activation stays scoped to
        // this test's app; the default file driver writes a process-global down
        // file that 503s unrelated tests in parallel workers.
        assert($this->app !== null);
        $this->app->instance(MaintenanceMode::class, new ArrayMaintenanceMode);

        Config::set('api-toolkit.maintenance_mode.except', ['health']);

        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/data', static fn (): array => ['ok' => true]);
        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/health', static fn (): array => ['ok' => true]);
    }

    /**
     * Test that a guarded route renders the service-unavailable envelope while
     * maintenance mode is active.
     *
     * @return void
     */
    public function testGuardedRouteRendersServiceUnavailableEnvelope(): void
    {
        $response = $this->duringMaintenance(fn () => $this->getJson('/data'));

        $response->assertStatus(503);
        $response->assertJsonPath('error.status', 503);
        $response->assertJsonPath('error.code', 10200);
    }

    /**
     * Test that a guarded route forwards the Retry-After and Refresh headers
     * when maintenance mode was activated with a retry and refresh interval.
     *
     * @return void
     */
    public function testGuardedRouteForwardsRetryAfterAndRefreshHeaders(): void
    {
        $response = $this->duringMaintenance(fn () => $this->getJson('/data'), ['retry' => 60, 'refresh' => '15']);

        $response->assertStatus(503);
        $response->assertJsonPath('error.status', 503);
        $response->assertJsonPath('error.code', 10200);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('Refresh', '15');
    }

    /**
     * Test that a guarded route sends no Retry-After or Refresh headers when
     * maintenance mode was activated without them.
     *
     * @return void
     */
    public function testGuardedRouteSendsNoRetryOrRefreshHeadersWithoutThem(): void
    {
        $response = $this->duringMaintenance(fn () => $this->getJson('/data'));

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 10200);
        $response->assertHeaderMissing('Retry-After');
        $response->assertHeaderMissing('Refresh');
    }

    /**
     * Test that a bypass-list route is served as normal during maintenance
     * mode.
     *
     * @return void
     */
    public function testBypassListIsServedDuringMaintenance(): void
    {
        $response = $this->duringMaintenance(fn () => $this->getJson('/health'));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    /**
     * Activate maintenance mode with the given payload overrides, run the
     * callback, and deactivate afterwards.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @param  array<string, mixed>  $overrides
     * @return TReturn
     */
    private function duringMaintenance(\Closure $callback, array $overrides = []): mixed
    {
        assert($this->app !== null);

        $app = $this->app;

        $app->maintenanceMode()->activate(array_merge([
            'except'   => [],
            'redirect' => null,
            'retry'    => null,
            'refresh'  => null,
            'secret'   => null,
            'status'   => 503,
            'template' => null,
        ], $overrides));

        try {
            return $callback();
        } finally {
            $app->maintenanceMode()->deactivate();
        }
    }
}
