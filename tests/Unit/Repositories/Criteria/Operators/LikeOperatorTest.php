<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LikeOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the LikeOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LikeOperator::class)]
class LikeOperatorTest extends TestCase
{
    /**
     * Test that apply wraps value with wildcards.
     *
     * @return void
     */
    public function testApplyWrapsValueWithWildcards(): void
    {
        $query    = (new User)->newQuery();
        $operator = new LikeOperator;

        $operator->apply($query, 'name', 'Ali', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('like', $wheres[0]['operator']);
        static::assertSame('%Ali%', $wheres[0]['value']);
        static::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply in or context uses orWhere.
     *
     * @return void
     */
    public function testLikeApplyInOrContextUsesOrWhere(): void
    {
        $query    = (new User)->newQuery();
        $operator = new LikeOperator;
        $context  = FilterContext::nested('$or', FilterContext::root());

        $operator->apply($query, 'name', 'Ali', $context);

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('like', $wheres[0]['operator']);
        static::assertSame('%Ali%', $wheres[0]['value']);
        static::assertSame('or', $wheres[0]['boolean']);
    }
}
