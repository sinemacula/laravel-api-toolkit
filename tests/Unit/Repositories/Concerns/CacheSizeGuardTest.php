<?php

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard;

/**
 * Tests for the CacheSizeGuard collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheSizeGuard::class)]
class CacheSizeGuardTest extends TestCase
{
    /**
     * Test that a result within both ceilings is allowed.
     *
     * @return void
     */
    public function testAllowsResultWithinCeilings(): void
    {
        $guard = new CacheSizeGuard(1000, 262144);

        static::assertTrue($guard->allows(collect(['a', 'b']), 2));
    }

    /**
     * Test that a result exceeding the row ceiling is rejected.
     *
     * @return void
     */
    public function testRejectsResultExceedingRowCeiling(): void
    {
        $guard = new CacheSizeGuard(2, 262144);

        static::assertFalse($guard->allows(collect(['a', 'b', 'c']), 3));
    }

    /**
     * Test that a result exceeding the byte ceiling is rejected.
     *
     * @return void
     */
    public function testRejectsResultExceedingByteCeiling(): void
    {
        $guard = new CacheSizeGuard(1000, 8);

        static::assertFalse($guard->allows(str_repeat('x', 256), 1));
    }

    /**
     * Test that a null row ceiling disables the row bound.
     *
     * @return void
     */
    public function testNullRowCeilingDisablesRowBound(): void
    {
        $guard = new CacheSizeGuard(null, 262144);

        static::assertTrue($guard->allows(collect(['a', 'b', 'c']), 100000));
    }

    /**
     * Test that a null byte ceiling disables the byte bound.
     *
     * @return void
     */
    public function testNullByteCeilingDisablesByteBound(): void
    {
        $guard = new CacheSizeGuard(1000, null);

        static::assertTrue($guard->allows(str_repeat('x', 100000), 1));
    }

    /**
     * Test that a result whose row count exactly equals the ceiling is
     * allowed, pinning the bound as exclusive rather than inclusive.
     *
     * @return void
     */
    public function testRowCountEqualToCeilingIsAllowed(): void
    {
        $guard = new CacheSizeGuard(5, 262144);

        static::assertTrue($guard->allows(collect(['a']), 5));
    }

    /**
     * Test that a result whose serialized size exactly equals the byte ceiling
     * is allowed, pinning the bound as exclusive rather than inclusive.
     *
     * @return void
     */
    public function testByteSizeEqualToCeilingIsAllowed(): void
    {
        $result = collect(['a', 'b']);
        $guard  = new CacheSizeGuard(1000, strlen(serialize($result)));

        static::assertTrue($guard->allows($result, 2));
    }
}
