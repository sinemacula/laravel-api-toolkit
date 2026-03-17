<?php

namespace Tests\Unit\Services\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;
use Tests\TestCase;

/**
 * Tests for the ServiceStatus enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceStatus::class)]
class ServiceStatusTest extends TestCase
{
    /**
     * Test that the enum has exactly three cases.
     *
     * @return void
     */
    public function testEnumHasThreeCases(): void
    {
        $cases = ServiceStatus::cases();

        static::assertCount(3, $cases);
    }

    /**
     * Test that the Pending case exists.
     *
     * @return void
     */
    public function testPendingCaseExists(): void
    {
        static::assertSame('Pending', ServiceStatus::Pending->name);
    }

    /**
     * Test that the Succeeded case exists.
     *
     * @return void
     */
    public function testSucceededCaseExists(): void
    {
        static::assertSame('Succeeded', ServiceStatus::Succeeded->name);
    }

    /**
     * Test that the Failed case exists.
     *
     * @return void
     */
    public function testFailedCaseExists(): void
    {
        static::assertSame('Failed', ServiceStatus::Failed->name);
    }

    /**
     * Test that all cases are distinct.
     *
     * @return void
     */
    public function testAllCasesAreDistinct(): void
    {
        static::assertNotSame(ServiceStatus::Pending, ServiceStatus::Succeeded);
        static::assertNotSame(ServiceStatus::Pending, ServiceStatus::Failed);
        static::assertNotSame(ServiceStatus::Succeeded, ServiceStatus::Failed);
    }
}
