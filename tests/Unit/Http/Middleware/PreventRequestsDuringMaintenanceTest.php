<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelMiddleware;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithNonPublicMembers;

/**
 * Tests for the PreventRequestsDuringMaintenance middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PreventRequestsDuringMaintenance::class)]
class PreventRequestsDuringMaintenanceTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that the middleware extends Laravel's PreventRequestsDuringMaintenance.
     *
     * @return void
     */
    public function testExtendsLaravelMiddleware(): void
    {
        static::assertContains(LaravelMiddleware::class, class_parents(PreventRequestsDuringMaintenance::class) ?: []);
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

        static::assertSame(['/health', '/status'], $except);
    }

    /**
     * Test that the constructor uses an empty array when config returns default.
     *
     * @return void
     */
    public function testConstructorUsesEmptyArrayWhenConfigReturnsDefault(): void
    {
        config()->set('api-toolkit.maintenance_mode.except', []);

        assert($this->app !== null);

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $except     = $this->getProperty($middleware, 'except');

        static::assertSame([], $except);
    }

    /**
     * Test that handle() passes the request through when the app is not in
     * maintenance mode.
     *
     * @return void
     */
    public function testHandlePassesThroughWhenNotInMaintenanceMode(): void
    {
        assert($this->app !== null);

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $request    = Request::create('/test', 'GET');
        $passed     = false;

        $middleware->handle($request, function () use (&$passed): string {
            $passed = true;

            return 'ok';
        });

        static::assertTrue($passed);
    }

    /**
     * Test that handle() converts an HttpException into a
     * MaintenanceModeException when the app is in maintenance mode.
     *
     * @return void
     */
    public function testHandleConvertsHttpExceptionToMaintenanceModeException(): void
    {
        assert($this->app !== null);

        $this->expectException(MaintenanceModeException::class);

        $middleware = new PreventRequestsDuringMaintenance($this->app);
        $request    = Request::create('/test', 'GET');

        $middleware->handle($request, function (): void {
            throw new HttpException(503, 'Service Unavailable');
        });
    }
}
