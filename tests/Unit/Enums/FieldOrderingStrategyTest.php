<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;

/**
 * Tests for the FieldOrderingStrategy enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldOrderingStrategy::class)]
final class FieldOrderingStrategyTest extends TestCase
{
    /**
     * Test that exactly the expected cases exist, in declaration order.
     *
     * @return void
     */
    public function testExposesTheExpectedCases(): void
    {
        $names = array_map(
            static fn (FieldOrderingStrategy $case): string => $case->name,
            FieldOrderingStrategy::cases(),
        );

        self::assertSame(['DEFAULT', 'BY_REQUESTED_FIELDS'], $names);
    }
}
