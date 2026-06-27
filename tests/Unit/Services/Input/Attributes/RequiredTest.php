<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\Attributes\Required;

/**
 * Tests for the Required validation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Required::class)]
final class RequiredTest extends TestCase
{
    /**
     * Test that toRules returns the required rule fragment.
     *
     * @return void
     */
    public function testToRulesReturnsRequired(): void
    {
        $attribute = new Required;

        self::assertSame(['required'], $attribute->toRules());
    }
}
