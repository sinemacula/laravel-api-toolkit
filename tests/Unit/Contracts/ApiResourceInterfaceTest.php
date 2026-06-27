<?php

declare(strict_types = 1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;

/**
 * Tests for the ApiResourceInterface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class ApiResourceInterfaceTest extends TestCase
{
    /**
     * Test that the interface defines getResourceType as a static method.
     *
     * @return void
     */
    public function testDefinesGetResourceTypeStaticMethod(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);
        $method     = $reflection->getMethod('getResourceType');

        self::assertTrue($method->isStatic());
        self::assertTrue($method->isPublic());
    }

    /**
     * Test that getResourceType returns a string.
     *
     * @return void
     */
    public function testGetResourceTypeReturnsString(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);
        $method     = $reflection->getMethod('getResourceType');

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
    }

    /**
     * Test that the interface defines getDefaultFields as a static method.
     *
     * @return void
     */
    public function testDefinesGetDefaultFieldsStaticMethod(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);
        $method     = $reflection->getMethod('getDefaultFields');

        self::assertTrue($method->isStatic());
        self::assertTrue($method->isPublic());
    }

    /**
     * Test that getDefaultFields returns an array.
     *
     * @return void
     */
    public function testGetDefaultFieldsReturnsArray(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);
        $method     = $reflection->getMethod('getDefaultFields');

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    /**
     * Test that the interface declares all required methods.
     *
     * @return void
     */
    public function testApiResourceInterfaceDeclaresAllRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);
        $methods    = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $expectedMethods = [
            'getResourceType',
            'getDefaultFields',
            'schema',
            'getAllFields',
            'resolveFields',
            'eagerLoadMapFor',
            'eagerLoadCountsFor',
            'eagerLoadSumsFor',
            'eagerLoadAveragesFor',
            'resolve',
            'withFields',
            'withoutFields',
            'withAll',
        ];

        $actualMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $methods,
        );

        self::assertCount(count($expectedMethods), $methods);

        foreach ($expectedMethods as $name) {
            self::assertContains($name, $actualMethods, "Missing method: {$name}");
        }
    }

    /**
     * Test that the interface is indeed an interface.
     *
     * @return void
     */
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);

        self::assertTrue($reflection->isInterface());
    }
}
