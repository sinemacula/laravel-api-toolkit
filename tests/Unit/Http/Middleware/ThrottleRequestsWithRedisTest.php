<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use Tests\Concerns\UsesRedis;
use Tests\TestCase;

/**
 * Tests for the ThrottleRequestsWithRedis middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequestsWithRedis::class)]
#[Group('redis')]
final class ThrottleRequestsWithRedisTest extends TestCase
{
    use UsesRedis;

    /**
     * Set up two routes the middleware limits to one request each.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.redis.options.prefix', uniqid('api-toolkit-throttle-', true) . ':');

        $this->requireRedis();

        Route::middleware(ThrottleRequestsWithRedis::class . ':1,1')->group(static function (): void {
            Route::get('/users/{user}', static fn (): array => []);
            Route::get('/posts', static fn (): array => []);
        });
    }

    /**
     * Test that the redis limiter refuses a request beyond the limit of a route
     * pattern while another pattern keeps its own budget.
     *
     * @return void
     */
    public function testEachRoutePatternIsLimitedOnItsOwnBudget(): void
    {
        $this->getJson('/users/1')->assertOk()->assertHeader('X-RateLimit-Remaining', 0);
        $this->getJson('/posts')->assertOk();
        $this->getJson('/users/2')->assertTooManyRequests();
    }
}
