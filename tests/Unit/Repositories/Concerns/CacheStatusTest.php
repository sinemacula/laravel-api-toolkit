<?php

namespace Tests\Unit\Repositories\Concerns;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStatus;

/**
 * Tests for the CacheStatus value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheStatus::class)]
class CacheStatusTest extends TestCase
{
    /**
     * Test that isPopulated returns true when the cache is populated.
     *
     * @return void
     */
    public function testIsPopulatedReturnsTrueWhenCacheIsPopulated(): void
    {
        $status = new CacheStatus(
            populated: true,
            age: 60,
            lastInvalidatedAt: null,
        );

        static::assertTrue($status->isPopulated());
    }

    /**
     * Test that isPopulated returns false when the cache is not
     * populated.
     *
     * @return void
     */
    public function testIsPopulatedReturnsFalseWhenCacheIsNotPopulated(): void
    {
        $status = new CacheStatus(
            populated: false,
            age: null,
            lastInvalidatedAt: null,
        );

        static::assertFalse($status->isPopulated());
    }

    /**
     * Test that getAge returns the number of seconds when the cache
     * is populated.
     *
     * @return void
     */
    public function testGetAgeReturnsSecondsWhenPopulated(): void
    {
        $status = new CacheStatus(
            populated: true,
            age: 120,
            lastInvalidatedAt: null,
        );

        static::assertSame(120, $status->getAge());
    }

    /**
     * Test that getAge returns null when the cache is not populated.
     *
     * @return void
     */
    public function testGetAgeReturnsNullWhenNotPopulated(): void
    {
        $status = new CacheStatus(
            populated: false,
            age: null,
            lastInvalidatedAt: null,
        );

        static::assertNull($status->getAge());
    }

    /**
     * Test that getLastInvalidatedAt returns the Carbon instance
     * when the cache has been previously invalidated.
     *
     * @return void
     */
    public function testGetLastInvalidatedAtReturnsCarbonInstanceWhenPreviouslyInvalidated(): void
    {
        $invalidatedAt = CarbonImmutable::parse('2026-01-15 10:30:00');

        $status = new CacheStatus(
            populated: true,
            age: 60,
            lastInvalidatedAt: $invalidatedAt,
        );

        static::assertSame($invalidatedAt, $status->getLastInvalidatedAt());
    }

    /**
     * Test that getLastInvalidatedAt returns null when the cache has
     * never been invalidated.
     *
     * @return void
     */
    public function testGetLastInvalidatedAtReturnsNullWhenNeverInvalidated(): void
    {
        $status = new CacheStatus(
            populated: true,
            age: 60,
            lastInvalidatedAt: null,
        );

        static::assertNull($status->getLastInvalidatedAt());
    }
}
