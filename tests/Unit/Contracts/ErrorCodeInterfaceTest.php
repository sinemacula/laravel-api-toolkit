<?php

declare(strict_types = 1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
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
#[CoversNothing]
final class ErrorCodeInterfaceTest extends TestCase
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());
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

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('int', $returnType->getName());
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

        self::assertCount(1, $methods);
    }

    /**
     * Test that the interface is indeed an interface.
     *
     * @return void
     */
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ErrorCodeInterface::class);

        self::assertTrue($reflection->isInterface());
    }
}
