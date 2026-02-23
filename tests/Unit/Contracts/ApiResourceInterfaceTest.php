<?php

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(ApiResourceInterface::class)]
class ApiResourceInterfaceTest extends TestCase
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

        static::assertTrue($method->isStatic());
        static::assertTrue($method->isPublic());
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

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('string', $returnType->getName());
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

        static::assertTrue($method->isStatic());
        static::assertTrue($method->isPublic());
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

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that the interface declares exactly two methods.
     *
     * @return void
     */
    public function testInterfaceDeclaresExactlyTwoMethods(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);
        $methods    = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        static::assertCount(2, $methods);
    }

    /**
     * Test that the interface is indeed an interface.
     *
     * @return void
     */
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ApiResourceInterface::class);

        static::assertTrue($reflection->isInterface());
    }
}
