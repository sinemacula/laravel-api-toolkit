<?php

declare(strict_types = 1);

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
final class SchemaIntrospectionProviderTest extends TestCase
{
    /**
     * Test that the SchemaIntrospectionProvider interface exists.
     *
     * @return void
     */
    public function testInterfaceExists(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);

        self::assertTrue($reflection->isInterface());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('model', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame(Model::class, $paramType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    /**
     * Test that getColumnDefinitions is declared with the correct signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresGetColumnDefinitionsMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('getColumnDefinitions');

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('model', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame(Model::class, $paramType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    /**
     * Test that getSearchableColumns is declared with the correct signature.
     *
     * @return void
     */
    public function testInterfaceDeclaresGetSearchableColumnsMethod(): void
    {
        $reflection = new \ReflectionClass(SchemaIntrospectionProvider::class);
        $method     = $reflection->getMethod('getSearchableColumns');

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('model', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame(Model::class, $paramType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('model', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        self::assertSame(Model::class, $firstParamType->getName());
        self::assertSame('column', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        self::assertSame('string', $secondParamType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('key', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        self::assertSame('string', $firstParamType->getName());
        self::assertSame('model', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        self::assertSame(Model::class, $secondParamType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
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

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('key', $parameters[0]->getName());

        $firstParamType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $firstParamType);
        self::assertSame('string', $firstParamType->getName());
        self::assertSame('model', $parameters[1]->getName());

        $secondParamType = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $secondParamType);
        self::assertSame(Model::class, $secondParamType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(Relation::class, $returnType->getName());
        self::assertTrue($returnType->allowsNull());
    }
}
