<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\ColumnProjection;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the ColumnProjection select narrowing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjection::class)]
final class ColumnProjectionTest extends TestCase
{
    /**
     * Test that a query selecting nothing yet selects the columns.
     *
     * @return void
     */
    public function testSelectsTheColumnsWhenNoneAreSet(): void
    {
        $query = User::query()->getQuery();

        self::assertTrue(ColumnProjection::apply($query, ['users.id', 'users.name']));
        self::assertSame(['users.id', 'users.name'], $query->columns);
    }

    /**
     * Test that the table wildcard is replaced in place, keeping the bound
     * subselects beside it, their bindings and their order.
     *
     * @return void
     */
    public function testReplacesTheTableWildcardKeepingTheSubselectsAndBindings(): void
    {
        $query = $this->countingPosts()->getQuery();

        $subselects = array_slice((array) $query->columns, 1);
        $bindings   = $query->getRawBindings()['select'];

        self::assertTrue(ColumnProjection::apply($query, ['users.id', 'users.name']));
        self::assertSame(['users.id', 'users.name', ...$subselects], $query->columns);
        self::assertSame(['First', 'Second'], $bindings);
        self::assertSame($bindings, $query->getRawBindings()['select']);
        self::assertContainsOnlyInstancesOf(Expression::class, $subselects);
        self::assertCount(2, $subselects);
    }

    /**
     * Test that a bare wildcard is replaced.
     *
     * @return void
     */
    public function testReplacesABareWildcard(): void
    {
        $query = User::query()->getQuery()->select('*');

        self::assertTrue(ColumnProjection::apply($query, ['users.id']));
        self::assertSame(['users.id'], $query->columns);
    }

    /**
     * Test that a select a caller chose, an explicit column or another table's
     * wildcard, is kept.
     *
     * @return void
     */
    public function testKeepsASelectTheCallerChose(): void
    {
        foreach ([['users.id', 'users.email'], ['posts.*'], ['users.*', 'users.email']] as $chosen) {

            $query = User::query()->getQuery()->select($chosen);

            self::assertFalse(ColumnProjection::apply($query, ['users.id']));
            self::assertSame($chosen, $query->columns);
        }
    }

    /**
     * Build a user query counting two differently constrained post sets.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function countingPosts(): Builder
    {
        return User::query()->withCount([
            'posts as first_count'  => static fn (Builder $posts): Builder => $posts->where('title', 'First'),
            'posts as second_count' => static fn (Builder $posts): Builder => $posts->where('title', 'Second'),
        ]);
    }
}
