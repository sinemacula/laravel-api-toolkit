<?php

namespace Tests\Unit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Tests for the SchemaIntrospectionProvider interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
class SchemaIntrospectionProviderTest extends TestCase
{
    /**
     * Test that the SchemaIntrospectionProvider interface exists.
     *
     * @return void
     */
    public function testInterfaceExists(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);

        static::assertTrue($reflection->isInterface());
    }

    /**
     * Test that getColumns is declared with the correct signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresGetColumnsMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('getColumns');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(1, $parameters);
        static::assertSame('model', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        static::assertSame(Model::class, $paramType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that getSearchableColumns is declared with the correct
     * signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresGetSearchableColumnsMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('getSearchableColumns');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(1, $parameters);
        static::assertSame('model', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        static::assertSame(Model::class, $paramType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('array', $returnType->getName());
    }

    /**
     * Test that isSearchable is declared with the correct signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresIsSearchableMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('isSearchable');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(2, $parameters);
        static::assertSame('model', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        static::assertSame(Model::class, $firstParamType->getName());
        static::assertSame('column', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        static::assertSame('string', $secondParamType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('bool', $returnType->getName());
    }

    /**
     * Test that isRelation is declared with the correct signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresIsRelationMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('isRelation');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(2, $parameters);
        static::assertSame('key', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        static::assertSame('string', $firstParamType->getName());
        static::assertSame('model', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        static::assertSame(Model::class, $secondParamType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('bool', $returnType->getName());
    }

    /**
     * Test that resolveRelation is declared with the correct signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresResolveRelationMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('resolveRelation');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(2, $parameters);
        static::assertSame('key', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        static::assertSame('string', $firstParamType->getName());
        static::assertSame('model', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        static::assertSame(Model::class, $secondParamType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame(Relation::class, $returnType->getName());
        static::assertTrue($returnType->allowsNull());
    }
}
