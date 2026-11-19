<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\MissingValue;

/**
 * Tests for the marker of a schema node without a value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MissingValue::class)]
final class MissingValueTest extends TestCase
{
    /**
     * Test that a missing value is unsatisfied unless marked undocumented.
     *
     * @return void
     */
    public function testValueIsUnsatisfiedUnlessMarkedUndocumented(): void
    {
        $unsatisfied  = new MissingValue('/properties/code');
        $undocumented = new MissingValue('/properties/meta', true);

        self::assertSame('/properties/code', $unsatisfied->pointer);
        self::assertFalse($unsatisfied->undocumented);
        self::assertSame('/properties/meta', $undocumented->pointer);
        self::assertTrue($undocumented->undocumented);
    }
}
