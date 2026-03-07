<?php

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
class FilterContextTest extends TestCase
{
    /**
     * Test that root returns null operator, not in relation, and depth zero.
     *
     * @return void
     */
    public function testRootReturnsNullOperatorNotInRelationDepthZero(): void
    {
        $context = FilterContext::root();

        static::assertNull($context->getLogicalOperator());
        static::assertFalse($context->isInRelation());
        static::assertSame(0, $context->getDepth());
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

        static::assertSame('$and', $nested->getLogicalOperator());
        static::assertSame(1, $nested->getDepth());
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

        static::assertTrue($nested->isInRelation());
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

        static::assertTrue($relation->isInRelation());
        static::assertSame($root->getDepth(), $relation->getDepth());
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

        static::assertSame('$or', $relation->getLogicalOperator());
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

        static::assertSame(0, $relation->getDepth());
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

        static::assertSame(2, $second->getDepth());
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

        static::assertNull($root->getLogicalOperator());
        static::assertSame(0, $root->getDepth());
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

        static::assertFalse($root->isInRelation());
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

        static::assertSame('$and', $relation->getLogicalOperator());
        static::assertTrue($relation->isInRelation());
        static::assertSame(1, $relation->getDepth());
    }
}
