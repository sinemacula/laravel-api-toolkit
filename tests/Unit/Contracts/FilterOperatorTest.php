<?php

declare(strict_types = 1);

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
final class FilterOperatorTest extends TestCase
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

        self::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('apply');

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $parameters = $method->getParameters();

        self::assertCount(4, $parameters);

        self::assertSame('query', $parameters[0]->getName());

        $queryType = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $queryType);
        self::assertSame(Builder::class, $queryType->getName());

        self::assertSame('column', $parameters[1]->getName());

        $columnType = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $columnType);
        self::assertSame('string', $columnType->getName());

        self::assertSame('value', $parameters[2]->getName());

        $valueType = $parameters[2]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $valueType);
        self::assertSame('mixed', $valueType->getName());

        self::assertSame('context', $parameters[3]->getName());

        $contextType = $parameters[3]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $contextType);
        self::assertSame(FilterContext::class, $contextType->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }
}
