<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the NotNullOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NotNullOperator::class)]
final class NotNullOperatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator */
    private NotNullOperator $operator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = new NotNullOperator;
    }

    /**
     * Test that apply adds a whereNotNull constraint.
     *
     * @return void
     */
    public function testApplyAddsWhereNotNull(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'name', true);

        $wheres = $query->getQuery()->wheres;

        self::assertSame('NotNull', $wheres[0]['type']);
        self::assertSame('name', $wheres[0]['column']);
    }

    /**
     * Test that apply joins an earlier clause with AND.
     *
     * @return void
     */
    public function testNotNullApplyJoinsAnEarlierClauseWithAnd(): void
    {
        $query = (new User)->newQuery();

        $query->where('email', 'alice@example.com');

        $this->operator->apply($query, 'name', true);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('NotNull', $wheres[1]['type']);
        self::assertSame('and', $wheres[1]['boolean']);
    }
}
