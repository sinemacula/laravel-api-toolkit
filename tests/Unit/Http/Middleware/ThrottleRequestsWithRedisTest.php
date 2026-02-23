<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis as BaseThrottleRequestsWithRedis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;

/**
 * Tests for the ThrottleRequestsWithRedis middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequestsWithRedis::class)]
class ThrottleRequestsWithRedisTest extends TestCase
{
    /**
     * Test that the middleware extends Laravel's ThrottleRequestsWithRedis.
     *
     * @return void
     */
    public function testExtendsBaseThrottleRequestsWithRedis(): void
    {
        static::assertTrue(is_subclass_of(ThrottleRequestsWithRedis::class, BaseThrottleRequestsWithRedis::class));
    }

    /**
     * Test that the middleware uses ThrottleRequestsTrait.
     *
     * @return void
     */
    public function testUsesThrottleRequestsTrait(): void
    {
        $traits = class_uses(ThrottleRequestsWithRedis::class);

        static::assertArrayHasKey(ThrottleRequestsTrait::class, $traits);
    }
}
