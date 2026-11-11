<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Support\ArrayMaintenanceMode;
use Tests\TestCase;

/**
 * Feature tests for the maintenance-mode middleware through the HTTP kernel.
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

        // The file driver's process-global down file would 503 parallel workers
        assert($this->app !== null);
        $this->app->instance(MaintenanceMode::class, new ArrayMaintenanceMode);

        Config::set('api-toolkit.maintenance_mode.except', ['health']);

        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/data', static fn (): array => ['ok' => true]);
        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/health', static fn (): array => ['ok' => true]);

        // Guarded only by the global stack the swap placed the middleware in
        Route::get('/open', static fn (): array => ['ok' => true]);
        Route::get('/broken', static function (): never {
            throw new \RuntimeException('Broken');
        });
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
     * Test that a guarded route forwards the Retry-After and Refresh headers.
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
     * none were set.
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
     * Test that the maintenance response is not reported, while a later
     * unhandled exception still is.
     *
     * @return void
     */
    public function testMaintenanceResponseIsNotReported(): void
    {
        $handler = app(ExceptionHandlerContract::class);

        /** @var \ArrayObject<int, \Throwable> $reported */
        $reported = new \ArrayObject;

        self::assertFalse($handler->shouldReport(new MaintenanceModeException(null, ['Retry-After' => '60'])));

        $this->exceptionConfiguration()->report(static function (\Throwable $exception) use ($reported): void {
            $reported->append($exception);
        });

        $this->duringMaintenance(fn () => $this->getJson('/data'), ['retry' => 60, 'refresh' => '15'])->assertStatus(503);

        self::assertCount(0, $reported);

        $this->getJson('/broken')->assertStatus(500);

        self::assertCount(1, $reported);
        self::assertInstanceOf(\RuntimeException::class, $reported[0]);
        self::assertSame('Broken', $reported[0]->getMessage());
    }

    /**
     * Test that the maintenance response carries the CORS headers for an
     * allowed origin.
     *
     * This holds only because the swap keeps the toolkit middleware after
     * HandleCors.
     *
     * @return void
     */
    public function testMaintenanceResponseCarriesCorsHeadersForAllowedOrigin(): void
    {
        $this->allowCorsOrigin('https://app.example.test');

        $response = $this->duringMaintenance(fn () => $this->getJson('/open', ['Origin' => 'https://app.example.test']), ['retry' => 60]);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 10200);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader('Retry-After', '60');
    }

    /**
     * Test that a CORS preflight request is answered during maintenance mode.
     *
     * @return void
     */
    public function testCorsPreflightIsAnsweredDuringMaintenance(): void
    {
        $this->allowCorsOrigin('https://app.example.test');

        $response = $this->duringMaintenance(fn () => $this->call('OPTIONS', '/open', [], [], [], [
            'HTTP_ORIGIN'                        => 'https://app.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]));

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');
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
     * Configure CORS to allow credentialed requests from the given origin.
     *
     * @param  string  $origin
     * @return void
     */
    private function allowCorsOrigin(string $origin): void
    {
        Config::set('cors.paths', ['*']);
        Config::set('cors.allowed_origins', [$origin]);
        Config::set('cors.allowed_methods', ['*']);
        Config::set('cors.allowed_headers', ['*']);
        Config::set('cors.supports_credentials', true);
    }

    /**
     * Activate maintenance mode with the given overrides around the callback.
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
