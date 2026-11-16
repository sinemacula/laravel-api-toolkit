<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the NullOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NullOperator::class)]
final class NullOperatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator */
    private NullOperator $operator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = new NullOperator;
    }

    /**
     * Test that apply adds a whereNull constraint.
     *
     * @return void
     */
    public function testApplyAddsWhereNull(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'name', true);

        $wheres = $query->getQuery()->wheres;

        self::assertSame('Null', $wheres[0]['type']);
        self::assertSame('name', $wheres[0]['column']);
    }

    /**
     * Test that apply joins an earlier clause with AND.
     *
     * @return void
     */
    public function testNullApplyJoinsAnEarlierClauseWithAnd(): void
    {
        $query = (new User)->newQuery();

        $query->where('email', 'alice@example.com');

        $this->operator->apply($query, 'name', true);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('Null', $wheres[1]['type']);
        self::assertSame('and', $wheres[1]['boolean']);
    }
}
