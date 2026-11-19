<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor;

/**
 * Tests for the query scope descriptor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryScopeDescriptor::class)]
final class QueryScopeDescriptorTest extends TestCase
{
    /**
     * Test that the descriptor exposes the scope's name, description, arguments
     * and whether only some callers may invoke it.
     *
     * @return void
     */
    public function testExposesTheNameDescriptionArgumentsAndCallerCondition(): void
    {
        $scope = new QueryScopeDescriptor('near', 'Within km of the point.', ['lat' => true, 'km' => false], true);

        self::assertSame('near', $scope->name);
        self::assertSame('Within km of the point.', $scope->description);
        self::assertSame(['lat' => true, 'km' => false], $scope->arguments);
        self::assertTrue($scope->conditional);
    }

    /**
     * Test that a scope described by name alone has no description and no
     * arguments, and is open to every caller.
     *
     * @return void
     */
    public function testANamedScopeAloneIsUndescribedArgumentFreeAndUnconditional(): void
    {
        $scope = new QueryScopeDescriptor('upcoming');

        self::assertNull($scope->description);
        self::assertSame([], $scope->arguments);
        self::assertFalse($scope->conditional);
    }
}
