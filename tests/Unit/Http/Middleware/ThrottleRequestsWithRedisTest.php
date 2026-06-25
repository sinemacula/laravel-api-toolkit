<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis as BaseThrottleRequestsWithRedis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;

/**
 * Tests for the ThrottleRequestsWithRedis middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequestsWithRedis::class)]
final class ThrottleRequestsWithRedisTest extends TestCase
{
    /**
     * Test that the middleware extends Laravel's ThrottleRequestsWithRedis.
     *
     * @return void
     */
    public function testExtendsBaseThrottleRequestsWithRedis(): void
    {
        $parents = class_parents(ThrottleRequestsWithRedis::class);

        self::assertIsArray($parents);
        self::assertArrayHasKey(BaseThrottleRequestsWithRedis::class, $parents);
    }

    /**
     * Test that the middleware uses ThrottleRequestsTrait.
     *
     * @return void
     */
    public function testUsesThrottleRequestsTrait(): void
    {
        $traits = class_uses(ThrottleRequestsWithRedis::class);

        self::assertArrayHasKey(ThrottleRequestsTrait::class, $traits);
    }
}
