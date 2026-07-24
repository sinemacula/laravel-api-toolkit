<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;

/**
 * Tests for the Undocumented attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Undocumented::class)]
final class UndocumentedTest extends TestCase
{
    /**
     * Test that the attribute can be instantiated.
     *
     * @return void
     */
    public function testCanBeInstantiated(): void
    {
        self::assertInstanceOf(Undocumented::class, new Undocumented);
    }
}
