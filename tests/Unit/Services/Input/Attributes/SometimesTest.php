<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\Attributes\Sometimes;

/**
 * Tests for the Sometimes validation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Sometimes::class)]
final class SometimesTest extends TestCase
{
    /**
     * Test that toRules returns the sometimes rule fragment.
     *
     * @return void
     */
    public function testToRulesReturnsSometimes(): void
    {
        $attribute = new Sometimes;

        self::assertSame(['sometimes'], $attribute->toRules());
    }
}
