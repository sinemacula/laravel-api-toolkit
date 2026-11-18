<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\UsesRedis;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Feature tests for the throttle alias on a redis cache store, limiting through
 * the redis server.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequestsWithRedis::class)]
#[CoversClass(ApiExceptionHandler::class)]
#[CoversTrait(ThrottleRequestsTrait::class)]
#[Group('redis')]
final class RedisThrottleTest extends TestCase
{
    use RegistersApiExceptionHandler, UsesRedis;

    /**
     * Set up each test with a route the alias limits to two requests.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireRedis();
        $this->registerApiExceptionHandler();

        Route::middleware('throttle:2,1')->get('/ping', static fn (): array => ['ok' => true]);
    }

    /**
     * Test that the redis limiter counts down the allowance and then renders
     * the toolkit throttled envelope.
     *
     * @return void
     */
    public function testExceedingTheLimitRendersTheThrottledEnvelope(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->getApplication()->make(Router::class);

        self::assertSame(ThrottleRequestsWithRedis::class, $router->getMiddleware()['throttle']);

        $this->getJson('/ping')->assertOk()->assertHeader('X-RateLimit-Limit', 2)->assertHeader('X-RateLimit-Remaining', 1);
        $this->getJson('/ping')->assertOk()->assertHeader('X-RateLimit-Limit', 2)->assertHeader('X-RateLimit-Remaining', 0);

        $response = $this->getJson('/ping');

        $response->assertStatus(429);
        $response->assertJsonPath('error.status', 429);
        $response->assertJsonPath('error.code', 10107);
        $response->assertHeader('X-RateLimit-Remaining', 0);
        $response->assertHeader('X-RateLimit-Reset');
        $response->assertHeader('Retry-After');
    }

    /**
     * Test that a caller exhausting its allowance does not throttle another
     * caller or a guest.
     *
     * @return void
     */
    public function testEachCallerHasItsOwnAllowance(): void
    {
        $one = User::create(['name' => 'One', 'email' => 'one@example.com']);
        $two = User::create(['name' => 'Two', 'email' => 'two@example.com']);

        $this->actingAs($one);
        $this->getJson('/ping')->assertOk();
        $this->getJson('/ping')->assertOk();
        $this->getJson('/ping')->assertStatus(429);

        $this->actingAs($two);
        $this->getJson('/ping')->assertOk();

        Auth::forgetGuards();

        $this->getJson('/ping')->assertOk();
    }

    /**
     * Point the default cache store at redis, keeping this test's keys apart
     * from every other run.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('cache.default', 'redis');
        $config->set('database.redis.options.prefix', uniqid('api-toolkit-throttle-', true) . ':');
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
