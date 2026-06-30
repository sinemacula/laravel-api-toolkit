<?php

declare(strict_types = 1);

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
final class ResourceMetadataProviderTest extends TestCase
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

        self::assertTrue($reflection->isInterface());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame('string', $paramType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame('string', $paramType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame('string', $paramType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        self::assertSame('string', $firstParamType->getName());
        self::assertSame('fields', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        self::assertSame('array', $secondParamType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        self::assertSame('string', $firstParamType->getName());
        self::assertSame('requestedAliases', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        self::assertSame('array', $secondParamType->getName());
        self::assertTrue($parameters[1]->allowsNull());
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertNull($parameters[1]->getDefaultValue());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    /**
     * Test that eagerLoadSumsFor is declared with string and nullable array
     * parameters.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresEagerLoadSumsFor(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('eagerLoadSumsFor');

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());
        self::assertSame('requestedSums', $parameters[1]->getName());
        self::assertTrue($parameters[1]->allowsNull());
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertNull($parameters[1]->getDefaultValue());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    /**
     * Test that eagerLoadAveragesFor is declared with string and nullable array
     * parameters.
     *
     * @return void
     */
    public function testResourceMetadataProviderDeclaresEagerLoadAveragesFor(): void
    {
        $reflection = new \ReflectionClass(ResourceMetadataProvider::class);
        $method     = $reflection->getMethod('eagerLoadAveragesFor');

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('resourceClass', $parameters[0]->getName());
        self::assertSame('requestedAverages', $parameters[1]->getName());
        self::assertTrue($parameters[1]->allowsNull());
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertNull($parameters[1]->getDefaultValue());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    /**
     * Test that a mock implementing the interface can be instantiated.
     *
     * @return void
     */
    public function testResourceMetadataProviderIsImplementable(): void
    {
        $mock = self::createStub(ResourceMetadataProvider::class);

        self::assertInstanceOf(ResourceMetadataProvider::class, $mock);
    }
}
