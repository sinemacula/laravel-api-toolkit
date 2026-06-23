<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Tests for the FilterContext value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterContext::class)]
final class FilterContextTest extends TestCase
{
    /**
     * Test that root returns null operator, not in relation, and depth zero.
     *
     * @return void
     */
    public function testRootReturnsNullOperatorNotInRelationDepthZero(): void
    {
        $context = FilterContext::root();

        self::assertNull($context->getLogicalOperator());
        self::assertFalse($context->isInRelation());
        self::assertSame(0, $context->getDepth());
    }

    /**
     * Test that nested increments depth and sets the operator.
     *
     * @return void
     */
    public function testNestedIncrementsDepthAndSetsOperator(): void
    {
        $root   = FilterContext::root();
        $nested = FilterContext::nested('$and', $root);

        self::assertSame('$and', $nested->getLogicalOperator());
        self::assertSame(1, $nested->getDepth());
    }

    /**
     * Test that nested inherits the relation flag from its parent.
     *
     * @return void
     */
    public function testNestedInheritsRelationFlagFromParent(): void
    {
        $root     = FilterContext::root();
        $relation = FilterContext::forRelation($root);
        $nested   = FilterContext::nested('$or', $relation);

        self::assertTrue($nested->isInRelation());
    }

    /**
     * Test that forRelation sets inRelation to true.
     *
     * @return void
     */
    public function testForRelationSetsInRelationTrue(): void
    {
        $root     = FilterContext::root();
        $relation = FilterContext::forRelation($root);

        self::assertTrue($relation->isInRelation());
        self::assertSame($root->getDepth(), $relation->getDepth());
    }

    /**
     * Test that forRelation inherits the operator from its parent.
     *
     * @return void
     */
    public function testForRelationInheritsOperatorFromParent(): void
    {
        $root     = FilterContext::root();
        $nested   = FilterContext::nested('$or', $root);
        $relation = FilterContext::forRelation($nested);

        self::assertSame('$or', $relation->getLogicalOperator());
    }

    /**
     * Test that forRelation does not increment depth.
     *
     * @return void
     */
    public function testForRelationDoesNotIncrementDepth(): void
    {
        $root     = FilterContext::root();
        $relation = FilterContext::forRelation($root);

        self::assertSame(0, $relation->getDepth());
    }

    /**
     * Test that chaining nested calls produces the correct depth.
     *
     * @return void
     */
    public function testNestedChainProducesCorrectDepth(): void
    {
        $root   = FilterContext::root();
        $first  = FilterContext::nested('$and', $root);
        $second = FilterContext::nested('$or', $first);

        self::assertSame(2, $second->getDepth());
    }

    /**
     * Test that the parent is unchanged after calling nested.
     *
     * @return void
     */
    public function testImmutabilityParentUnchangedAfterNested(): void
    {
        $root = FilterContext::root();

        FilterContext::nested('$and', $root);

        self::assertNull($root->getLogicalOperator());
        self::assertSame(0, $root->getDepth());
    }

    /**
     * Test that the parent is unchanged after calling forRelation.
     *
     * @return void
     */
    public function testImmutabilityParentUnchangedAfterForRelation(): void
    {
        $root = FilterContext::root();

        FilterContext::forRelation($root);

        self::assertFalse($root->isInRelation());
    }

    /**
     * Test that nested then forRelation produces the correct compound state.
     *
     * @return void
     */
    public function testNestedThenForRelationCompoundState(): void
    {
        $root     = FilterContext::root();
        $nested   = FilterContext::nested('$and', $root);
        $relation = FilterContext::forRelation($nested);

        self::assertSame('$and', $relation->getLogicalOperator());
        self::assertTrue($relation->isInRelation());
        self::assertSame(1, $relation->getDepth());
    }
}
