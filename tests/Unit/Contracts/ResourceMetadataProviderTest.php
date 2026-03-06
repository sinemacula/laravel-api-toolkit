<?php

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;

/**
 * Tests for the ResourceMetadataProvider interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
class ResourceMetadataProviderTest extends TestCase
{
    /**
     * Test that the ResourceMetadataProvider interface exists and is an
     * interface.
     *
     * @return void
     */
    public function testResourceMetadataProviderInterfaceExists(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);

        static::assertTrue($reflection->isInterface());
    }

    /**
     * Test that getResourceType is declared with a string parameter and string
     * return type.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresGetResourceType(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('getResourceType');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(1, $parameters);
        static::assertSame('resourceClass', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        static::assertSame('string', $paramType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('string', $returnType->getName());
    }

    /**
     * Test that resolveFields is declared with a string parameter and array
     * return type.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresResolveFields(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('resolveFields');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(1, $parameters);
        static::assertSame('resourceClass', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        static::assertSame('string', $paramType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that getAllFields is declared with a string parameter and array
     * return type.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresGetAllFields(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('getAllFields');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(1, $parameters);
        static::assertSame('resourceClass', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        static::assertSame('string', $paramType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that eagerLoadMapFor is declared with string and array parameters.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresEagerLoadMapFor(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('eagerLoadMapFor');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(2, $parameters);
        static::assertSame('resourceClass', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        static::assertSame('string', $firstParamType->getName());
        static::assertSame('fields', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        static::assertSame('array', $secondParamType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that eagerLoadCountsFor is declared with string and nullable array
     * parameters.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresEagerLoadCountsFor(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('eagerLoadCountsFor');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(2, $parameters);
        static::assertSame('resourceClass', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        static::assertSame('string', $firstParamType->getName());
        static::assertSame('requestedAliases', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        static::assertSame('array', $secondParamType->getName());
        static::assertTrue($parameters[1]->allowsNull());
        static::assertTrue($parameters[1]->isDefaultValueAvailable());
        static::assertNull($parameters[1]->getDefaultValue());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that a mock implementing the interface can be instantiated.
     *
     * @return void
     */
    public function testResourceMetadataProviderIsImplementable(): void
    {
        $mock = $this->createMock(ResourceMetadataProvider::class);

        static::assertInstanceOf(ResourceMetadataProvider::class, $mock);
    }
}
