<?php

namespace Tests\Unit\Services\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;

/**
 * Tests for the ServiceConcern interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceConcern::class)]
class ServiceConcernTest extends TestCase
{
    /**
     * Test that the ServiceConcern interface exists and is an interface.
     *
     * @return void
     */
    public function testServiceConcernInterfaceExists(): void
    {
        static::assertTrue(interface_exists(ServiceConcern::class));

        $reflection = new \ReflectionClass(ServiceConcern::class);

        static::assertTrue($reflection->isInterface());
    }

    /**
     * Test that the ServiceConcern interface declares an execute method
     * with the expected signature.
     *
     * @return void
     */
    public function testServiceConcernInterfaceDeclaresExecuteMethod(): void
    {
        $reflection = new \ReflectionClass(ServiceConcern::class);

        static::assertTrue($reflection->hasMethod('execute'));

        $method     = $reflection->getMethod('execute');
        $parameters = $method->getParameters();

        static::assertCount(2, $parameters);

        $serviceParam = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $serviceParam);
        static::assertSame('SineMacula\ApiToolkit\Services\Service', $serviceParam->getName());

        $nextParam = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $nextParam);
        static::assertSame(\Closure::class, $nextParam->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('bool', $returnType->getName());
    }
}
