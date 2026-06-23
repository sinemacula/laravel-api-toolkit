<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
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
#[CoversNothing]
final class ServiceConcernTest extends TestCase
{
    /**
     * Test that the ServiceConcern interface exists and is an interface.
     *
     * @return void
     */
    public function testServiceConcernInterfaceExists(): void
    {
        self::assertTrue(interface_exists(ServiceConcern::class));

        $reflection = new \ReflectionClass(ServiceConcern::class);

        self::assertTrue($reflection->isInterface());
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

        self::assertTrue($reflection->hasMethod('execute'));

        $method     = $reflection->getMethod('execute');
        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);

        $serviceParam = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $serviceParam);
        self::assertSame('SineMacula\ApiToolkit\Services\Service', $serviceParam->getName());

        $nextParam = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $nextParam);
        self::assertSame(\Closure::class, $nextParam->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
    }
}
