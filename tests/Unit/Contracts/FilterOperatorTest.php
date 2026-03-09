<?php

namespace Tests\Unit\Contracts;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Tests for the FilterOperator interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
class FilterOperatorTest extends TestCase
{
    /**
     * Test that the FilterOperator interface declares the apply method
     * with the correct signature.
     *
     * @return void
     */
    public function testFilterOperatorInterfaceDefinesApplyMethod(): void
    {
        $reflection = new \ReflectionClass(FilterOperator::class);

        static::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('apply');

        static::assertTrue($method->isPublic());
        static::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        static::assertCount(4, $parameters);

        static::assertSame('query', $parameters[0]->getName());

        $queryType = $parameters[0]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $queryType);
        static::assertSame(Builder::class, $queryType->getName());

        static::assertSame('column', $parameters[1]->getName());

        $columnType = $parameters[1]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $columnType);
        static::assertSame('string', $columnType->getName());

        static::assertSame('value', $parameters[2]->getName());

        $valueType = $parameters[2]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $valueType);
        static::assertSame('mixed', $valueType->getName());

        static::assertSame('context', $parameters[3]->getName());

        $contextType = $parameters[3]->getType();

        static::assertInstanceOf(\ReflectionNamedType::class, $contextType);
        static::assertSame(FilterContext::class, $contextType->getName());

        $returnType = $method->getReturnType();

        static::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        static::assertSame('void', $returnType->getName());
    }
}
