<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;

/**
 * Tests for the service source enumeration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceSource::class)]
final class ServiceSourceTest extends TestCase
{
    /**
     * Test that the enum declares exactly the expected cases.
     *
     * @return void
     */
    public function testDeclaresExpectedCases(): void
    {
        $names = array_map(static fn (ServiceSource $case): string => $case->name, ServiceSource::cases());

        self::assertSame(['HTTP', 'QUEUE', 'CONSOLE', 'INTERNAL'], $names);
    }
}
