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
final class LikeOperatorTest extends TestCase
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

    /**
     * Test that apply with a Stringable value casts it to its string
     * representation before wrapping with wildcards.
     *
     * @return void
     */
    public function testApplyWithStringableValueUsesStringRepresentation(): void
    {
        $query    = (new User)->newQuery();
        $operator = new LikeOperator;

        $value = new class implements \Stringable {
            /**
             * Render the value as a string.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'Ali';
            }
        };

        $operator->apply($query, 'name', $value, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('%Ali%', $wheres[0]['value']);
    }

    /**
     * Test that apply with a non-scalar, non-Stringable value falls
     * back to an empty search term.
     *
     * @return void
     */
    public function testApplyWithArrayValueFallsBackToEmptyTerm(): void
    {
        $query    = (new User)->newQuery();
        $operator = new LikeOperator;

        $operator->apply($query, 'name', ['Ali'], FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('%%', $wheres[0]['value']);
    }
}
