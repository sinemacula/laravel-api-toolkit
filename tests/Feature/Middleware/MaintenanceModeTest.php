<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkPreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use Tests\Concerns\RecordsStatements;
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
    use RecordsStatements, RegistersApiExceptionHandler;

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

        Config::set('api-toolkit.maintenance_mode.except', ['health', 'api/health/*']);

        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/data', static fn (): array => ['ok' => true]);
        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/health', static fn (): array => ['ok' => true]);
        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/api/health/live', static fn (): array => ['ok' => true]);
        Route::middleware(PreventRequestsDuringMaintenance::class)->get('/status', static fn (): array => ['ok' => true]);

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
     * Test that a guarded route is refused without touching the database.
     *
     * @return void
     */
    public function testGuardedRouteIsRefusedWithoutStatements(): void
    {
        $statements = $this->recordStatements(fn () => $this->duringMaintenance(fn () => $this->getJson('/data')->assertStatus(503)));

        self::assertStatementCount(0, $statements);
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
     * Test that a URI matching a wildcard bypass-list entry is served during
     * maintenance mode.
     *
     * @return void
     */
    public function testWildcardExceptIsServedDuringMaintenance(): void
    {
        $this->duringMaintenance(fn () => $this->getJson('/api/health/live'))->assertOk();
    }

    /**
     * Test that a URI excluded through the framework middleware is served
     * during maintenance mode.
     *
     * @return void
     */
    public function testFrameworkLevelExceptIsHonoured(): void
    {
        $this->duringMaintenance(fn () => $this->getJson('/status'))->assertStatus(503);

        FrameworkPreventRequestsDuringMaintenance::except(['status']);

        try {
            $this->duringMaintenance(fn () => $this->getJson('/status'))->assertOk();
        } finally {
            FrameworkPreventRequestsDuringMaintenance::flushState();
        }
    }

    /**
     * Test that a JSON client gets the maintenance envelope although the
     * maintenance payload redirects and renders a template for other clients.
     *
     * @return void
     */
    public function testJsonClientGetsEnvelopeDespiteRedirectAndTemplate(): void
    {
        $payload = ['redirect' => '/elsewhere', 'template' => 'Down'];

        $this->duringMaintenance(fn () => $this->get('/data'), $payload)->assertRedirect('/elsewhere');
        $this->duringMaintenance(fn () => $this->get('/elsewhere'), $payload)->assertStatus(503)->assertContent('Down');

        $response = $this->duringMaintenance(fn () => $this->getJson('/data'), $payload);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 10200);
    }

    /**
     * Provide each strategy that defers a non-JSON request to Laravel's
     * renderer, with the debug mode it defers under.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function deferringStrategyProvider(): iterable
    {
        yield 'json_when_expected' => ['json_when_expected', false];
        yield 'auto in debug' => ['auto', true];
    }

    /**
     * Test that a non-JSON request deferred to Laravel's renderer during
     * maintenance mode is answered 503 with Retry-After.
     *
     * @param  string  $strategy
     * @param  bool  $debug
     * @return void
     */
    #[DataProvider('deferringStrategyProvider')]
    public function testNonJsonRequestDuringMaintenanceIsServiceUnavailable(string $strategy, bool $debug): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', $strategy);
        Config::set('app.debug', $debug);

        $response = $this->duringMaintenance(fn () => $this->get('/data', ['Accept' => 'text/html']), ['retry' => 60]);

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
    }

    /**
     * Test that visiting the maintenance secret issues a valid bypass cookie.
     *
     * @return void
     */
    public function testSecretPathIssuesBypassCookie(): void
    {
        $response = $this->duringMaintenance(fn () => $this->get('/abc'), ['secret' => 'abc']);

        $response->assertRedirect('/');

        $cookie = $response->getCookie('laravel_maintenance', false);

        self::assertNotNull($cookie);
        self::assertTrue(MaintenanceModeBypassCookie::isValid((string) $cookie->getValue(), 'abc'));
    }

    /**
     * Test that a valid bypass cookie serves a guarded route during maintenance
     * mode.
     *
     * @return void
     */
    public function testBypassCookieServesGuardedJsonRoute(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('abc');

        $response = $this->duringMaintenance(
            fn () => $this->withCredentials()->withUnencryptedCookie('laravel_maintenance', (string) $cookie->getValue())->getJson('/data'),
            ['secret' => 'abc'],
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    /**
     * Test that a bypass cookie minted for another secret still gets the
     * maintenance envelope.
     *
     * @return void
     */
    public function testForgedBypassCookieStillGetsMaintenanceEnvelope(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('forged');

        $response = $this->duringMaintenance(
            fn () => $this->withCredentials()->withUnencryptedCookie('laravel_maintenance', (string) $cookie->getValue())->getJson('/data'),
            ['secret' => 'abc'],
        );

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 10200);
    }

    /**
     * Provide application names with the maintenance detail each renders.
     *
     * @return iterable<string, array{string|null, string}>
     */
    public static function applicationNameProvider(): iterable
    {
        yield 'string name' => ['Acme', 'Acme is currently in maintenance mode, please try again a little later'];
        yield 'missing name' => [null, 'The application is currently in maintenance mode, please try again a little later'];
    }

    /**
     * Test that the maintenance detail names the application, or falls back to
     * a generic subject when the name is not a string.
     *
     * @param  string|null  $name
     * @param  string  $detail
     * @return void
     */
    #[DataProvider('applicationNameProvider')]
    public function testMaintenanceDetailNamesTheApplication(?string $name, string $detail): void
    {
        Config::set('app.name', $name);

        $this->duringMaintenance(fn () => $this->getJson('/data'))->assertJsonPath('error.detail', $detail);
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
