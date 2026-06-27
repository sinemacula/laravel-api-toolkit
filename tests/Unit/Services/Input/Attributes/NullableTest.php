<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\Attributes\Nullable;

/**
 * Tests for the Nullable validation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Nullable::class)]
final class NullableTest extends TestCase
{
    /**
     * Test that toRules returns the nullable rule fragment.
     *
     * @return void
     */
    public function testToRulesReturnsNullable(): void
    {
        $attribute = new Nullable;

        self::assertSame(['nullable'], $attribute->toRules());
    }
}
