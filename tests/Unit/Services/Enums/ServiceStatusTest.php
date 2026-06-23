<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;

/**
 * Tests for the service status enumeration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceStatus::class)]
final class ServiceStatusTest extends TestCase
{
    /**
     * Test that the enum defines exactly the expected cases.
     *
     * @return void
     */
    public function testDefinesExpectedCases(): void
    {
        $names = array_map(static fn (ServiceStatus $case): string => $case->name, ServiceStatus::cases());

        self::assertSame(['SUCCEEDED', 'FAILED'], $names);
    }
}
