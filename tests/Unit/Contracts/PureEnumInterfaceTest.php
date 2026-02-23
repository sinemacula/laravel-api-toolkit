<?php

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\PureEnumInterface;

/**
 * Tests for the PureEnumInterface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PureEnumInterface::class)]
class PureEnumInterfaceTest extends TestCase
{
    /**
     * Test that the interface defines tryFrom as a static method.
     *
     * @return void
     */
    public function testDefinesTryFromStaticMethod(): void
    {
        $reflection = new \ReflectionClass(PureEnumInterface::class);
        $method     = $reflection->getMethod('tryFrom');

        static::assertTrue($method->isStatic());
        static::assertTrue($method->isPublic());
    }

    /**
     * Test that tryFrom accepts a mixed parameter.
     *
     * @return void
     */
    public function testTryFromAcceptsMixedParameter(): void
    {
        $reflection = new \ReflectionClass(PureEnumInterface::class);
        $method     = $reflection->getMethod('tryFrom');
        $parameters = $method->getParameters();

        static::assertCount(1, $parameters);
        static::assertSame('value', $parameters[0]->getName());
        $paramType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        static::assertSame('mixed', $paramType->getName());
    }

    /**
     * Test that tryFrom has a nullable return type.
     *
     * @return void
     */
    public function testTryFromHasNullableReturnType(): void
    {
        $reflection = new \ReflectionClass(PureEnumInterface::class);
        $method     = $reflection->getMethod('tryFrom');

        static::assertTrue($method->getReturnType()?->allowsNull());
    }

    /**
     * Test that the interface declares exactly one method.
     *
     * @return void
     */
    public function testInterfaceDeclaresExactlyOneMethod(): void
    {
        $reflection = new \ReflectionClass(PureEnumInterface::class);
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
        $reflection = new \ReflectionClass(PureEnumInterface::class);

        static::assertTrue($reflection->isInterface());
    }
}
