<?php

declare(strict_types = 1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
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
#[CoversNothing]
final class PureEnumInterfaceTest extends TestCase
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

        self::assertTrue($method->isStatic());
        self::assertTrue($method->isPublic());
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

        self::assertCount(1, $parameters);
        self::assertSame('value', $parameters[0]->getName());
        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame('mixed', $paramType->getName());
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

        self::assertTrue($method->getReturnType()?->allowsNull());
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

        self::assertCount(1, $methods);
    }

    /**
     * Test that the interface is indeed an interface.
     *
     * @return void
     */
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(PureEnumInterface::class);

        self::assertTrue($reflection->isInterface());
    }
}
