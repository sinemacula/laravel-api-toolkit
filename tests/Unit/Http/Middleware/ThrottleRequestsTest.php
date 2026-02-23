<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;

/**
 * Tests for the ThrottleRequests middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequests::class)]
class ThrottleRequestsTest extends TestCase
{
    /**
     * Test that the middleware extends Laravel's ThrottleRequests.
     *
     * @return void
     */
    public function testExtendsBaseThrottleRequests(): void
    {
        static::assertTrue(is_subclass_of(ThrottleRequests::class, BaseThrottleRequests::class));
    }

    /**
     * Test that the middleware uses ThrottleRequestsTrait.
     *
     * @return void
     */
    public function testUsesThrottleRequestsTrait(): void
    {
        $traits = class_uses(ThrottleRequests::class);

        static::assertArrayHasKey(ThrottleRequestsTrait::class, $traits);
    }
}
