<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelMiddleware;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Support\ArrayMaintenanceMode;

/**
 * Tests for the PreventRequestsDuringMaintenance middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PreventRequestsDuringMaintenance::class)]
final class PreventRequestsDuringMaintenanceTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that the middleware extends Laravel's
     * PreventRequestsDuringMaintenance.
     *
     * @return void
     */
    public function testExtendsLaravelMiddleware(): void
    {
        self::assertContains(LaravelMiddleware::class, class_parents(PreventRequestsDuringMaintenance::class) ?: []);
    }

    /**
     * Test that the constructor sets except from config.
     *
     * @return void
     */
    public function testConstructorSetsExceptFromConfig(): void
    {
        config()->set('api-toolkit.maintenance_mode.except', ['/health', '/status']);

        assert($this->app !== null);

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $except     = $this->getProperty($middleware, 'except');

        self::assertSame(['/health', '/status'], $except);
    }

    /**
     * Test that the constructor uses an empty array when config returns
     * default.
     *
     * @return void
     */
    public function testConstructorUsesEmptyArrayWhenConfigReturnsDefault(): void
    {
        config()->set('api-toolkit.maintenance_mode.except', []);

        assert($this->app !== null);

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $except     = $this->getProperty($middleware, 'except');

        self::assertSame([], $except);
    }

    /**
     * Test that handle() passes the request through when the app is not in
     * maintenance mode.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\MaintenanceModeException
     */
    public function testHandlePassesThroughWhenNotInMaintenanceMode(): void
    {
        assert($this->app !== null);

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $request    = Request::create('/test', HttpMethod::GET->getVerb());
        $passed     = false;

        $middleware->handle($request, function () use (&$passed): string {
            $passed = true;

            return 'ok';
        });

        self::assertTrue($passed);
    }

    /**
     * Test that handle() forwards the Retry-After and Refresh headers from the
     * maintenance payload onto the toolkit exception.
     *
     * @return void
     */
    public function testHandleForwardsRetryAfterAndRefreshHeaders(): void
    {
        $exception = $this->handleDuringMaintenance(['retry' => 60, 'refresh' => '15']);

        self::assertSame(['Retry-After' => 60, 'Refresh' => '15'], $exception->getHeaders());
        self::assertSame(503, $exception->getStatusCode());
        self::assertNull($exception->getPrevious());
        self::assertNull($exception->getCustomMeta());
    }

    /**
     * Test that handle() forwards a Retry-After date when maintenance mode was
     * activated with a retry datetime.
     *
     * @return void
     */
    public function testHandleForwardsRfc7231RetryAfterDate(): void
    {
        $exception = $this->handleDuringMaintenance(['retry' => 'Wed, 21 Oct 2026 07:28:00 GMT']);

        self::assertSame(['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], $exception->getHeaders());
    }

    /**
     * Test that handle() sends no Retry-After or Refresh headers when
     * maintenance mode was activated without them.
     *
     * @return void
     */
    public function testHandleSendsNoRetryOrRefreshHeadersWithoutThem(): void
    {
        $exception = $this->handleDuringMaintenance();

        self::assertSame([], $exception->getHeaders());
    }

    /**
     * Test that handle() keeps the service-unavailable status whatever status
     * maintenance mode was activated with.
     *
     * @return void
     */
    public function testHandleKeepsServiceUnavailableStatusWhateverStatusIsConfigured(): void
    {
        $exception = $this->handleDuringMaintenance(['status' => 500]);

        self::assertSame(503, $exception->getStatusCode());
    }

    /**
     * Activate maintenance mode with the given payload overrides and return the
     * exception the middleware throws for a guarded request.
     *
     * @param  array<string, mixed>  $overrides
     * @return \SineMacula\ApiToolkit\Exceptions\MaintenanceModeException
     */
    private function handleDuringMaintenance(array $overrides = []): MaintenanceModeException
    {
        assert($this->app !== null);

        $maintenance = new ArrayMaintenanceMode;

        $this->app->instance(MaintenanceMode::class, $maintenance);

        $maintenance->activate(array_merge([
            'except'   => [],
            'redirect' => null,
            'retry'    => null,
            'refresh'  => null,
            'secret'   => null,
            'status'   => 503,
            'template' => null,
        ], $overrides));

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $request    = Request::create('/test', HttpMethod::GET->getVerb());

        try {
            $middleware->handle($request, static fn (): string => 'ok');

            self::fail('Expected a MaintenanceModeException to be thrown.');
        } catch (MaintenanceModeException $exception) {
            return $exception;
        }
    }
}
