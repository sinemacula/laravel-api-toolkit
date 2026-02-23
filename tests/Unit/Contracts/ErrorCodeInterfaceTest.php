<?php

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;

/**
 * Tests for the ErrorCodeInterface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorCodeInterface::class)]
class ErrorCodeInterfaceTest extends TestCase
{
    /**
     * Test that the interface defines getCode as a public method.
     *
     * @return void
     */
    public function testDefinesGetCodeMethod(): void
    {
        $reflection = new \ReflectionClass(ErrorCodeInterface::class);
        $method     = $reflection->getMethod('getCode');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());
    }

    /**
     * Test that getCode returns an int.
     *
     * @return void
     */
    public function testGetCodeReturnsInt(): void
    {
        $reflection = new \ReflectionClass(ErrorCodeInterface::class);
        $method     = $reflection->getMethod('getCode');

        static::assertSame('int', $method->getReturnType()?->getName());
    }

    /**
     * Test that the interface declares exactly one method.
     *
     * @return void
     */
    public function testInterfaceDeclaresExactlyOneMethod(): void
    {
        $reflection = new \ReflectionClass(ErrorCodeInterface::class);
        $methods    = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        static::assertCount(1, $methods);
    }

    /**
     * Test that the interface is indeed an interface.
     *
     * @return void
     */
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ErrorCodeInterface::class);

        static::assertTrue($reflection->isInterface());
    }
}
