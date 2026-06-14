<?php

namespace Tests\Unit\RouteLinting\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor;
use Tests\TestCase;

/**
 * Tests for the RouteDescriptor DTO.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteDescriptor::class)]
class RouteDescriptorTest extends TestCase
{
    /**
     * Test that all four properties round-trip correctly, including isVendor
     * for both true and false, and that name may be null.
     *
     * @return void
     */
    public function testExposesUriMethodsNameAndVendorFlag(): void
    {
        // Arrange & Act — named route, non-vendor
        $named = new RouteDescriptor(
            uri: 'users/{user}',
            methods: ['GET', 'HEAD'],
            name: 'users.show',
            isVendor: false,
        );

        // Assert
        static::assertSame('users/{user}', $named->uri);
        static::assertSame(['GET', 'HEAD'], $named->methods);
        static::assertSame('users.show', $named->name);
        static::assertFalse($named->isVendor);

        // Arrange & Act — unnamed vendor route
        $vendor = new RouteDescriptor(
            uri: 'vendor/package/resource',
            methods: ['POST'],
            name: null,
            isVendor: true,
        );

        // Assert
        static::assertSame('vendor/package/resource', $vendor->uri);
        static::assertSame(['POST'], $vendor->methods);
        static::assertNull($vendor->name);
        static::assertTrue($vendor->isVendor);
    }
}
