<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelMiddleware;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
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
        Config::shouldReceive('get')
            ->with('api-toolkit.maintenance_mode.except', [])
            ->once()
            ->andReturn(['/health', '/status']);

        $app = $this->createAppMock();

        $middleware = new PreventRequestsDuringMaintenance($app);

        $except = $this->getProperty($middleware, 'except');

        static::assertSame(['/health', '/status'], $except);

        \Mockery::close();
    }

    /**
     * Test that the constructor uses an empty array when config returns default.
     *
     * @return void
     */
    public function testConstructorUsesEmptyArrayWhenConfigReturnsDefault(): void
    {
        Config::shouldReceive('get')
            ->with('api-toolkit.maintenance_mode.except', [])
            ->once()
            ->andReturn([]);

        $app = $this->createAppMock();

        $middleware = new PreventRequestsDuringMaintenance($app);

        $except = $this->getProperty($middleware, 'except');

        static::assertSame([], $except);

        \Mockery::close();
    }

    /**
     * Create a mock Application instance.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    private function createAppMock(): \Illuminate\Contracts\Foundation\Application
    {
        $app = $this->createMock(\Illuminate\Contracts\Foundation\Application::class);
        $app->method('isDownForMaintenance')->willReturn(false);
        $app->method('maintenanceMode')->willReturn(
            $this->createMock(\Illuminate\Contracts\Foundation\MaintenanceMode::class),
        );

        return $app;
    }
}
