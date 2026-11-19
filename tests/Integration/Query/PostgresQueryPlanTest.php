<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;

/**
 * Query plan suite for the PostgreSQL engine.
 *
 * An index on the ordered column alone leaves rows sharing a value unordered by
 * key, which an incremental sort settles a group at a time.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PageOrder::class)]
#[CoversClass(RelationWindow::class)]
#[Group('pgsql')]
final class PostgresQueryPlanTest extends EngineQueryPlanTestCase
{
    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'pgsql';
    }

    /**
     * Assert that the engine reads the statement's rows in order from the
     * index, sorting at most the rows sharing one indexed value.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    #[\Override]
    protected function assertOrderedFrom(string $index, array $statement): void
    {
        $plan = $this->plan($statement);

        self::assertMatchesRegularExpression(sprintf('/Index (Only )?Scan (Backward )?using %s on users/', $index), $plan);
        self::assertDoesNotMatchRegularExpression('/^\s*(->\s+)?Sort$/m', $plan);
    }

    /**
     * Assert that the engine reads the statement's rows in order from the index
     * without sorting any of them.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    #[\Override]
    protected function assertOrderedWithoutSortingFrom(string $index, array $statement): void
    {
        $this->assertOrderedFrom($index, $statement);

        self::assertStringNotContainsString('Sort', $this->plan($statement));
    }

    /**
     * Assert that the engine starts its ordered read of the index at the
     * cursor's boundary.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    #[\Override]
    protected function assertSeeks(string $index, array $statement): void
    {
        $this->assertOrderedFrom($index, $statement);

        self::assertStringContainsString('Index Cond', $this->plan($statement));
    }

    /**
     * Assert that the engine finds the bounded posts through an index leading
     * with their owner.
     *
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    #[\Override]
    protected function assertReadThroughOwnerIndex(array $statement): void
    {
        $plan = $this->plan($statement);

        self::assertMatchesRegularExpression('/(using|on) posts_user_id/', $plan);
        self::assertStringNotContainsString('Seq Scan on posts', $plan);
    }

    /**
     * Bring the planner's statistics up to date with the seeded rows.
     *
     * @return void
     */
    #[\Override]
    protected function refreshStatistics(): void
    {
        DB::statement('analyze users');
        DB::statement('analyze posts');
    }

    /**
     * Return the plan for the statement with sequential scans priced out.
     *
     * The question is whether an index can serve the read, not whether the
     * planner prefers one on a table this small.
     *
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return string
     */
    private function plan(array $statement): string
    {
        DB::statement('set enable_seqscan = off');

        try {
            $rows = DB::select('explain (costs off) ' . $statement['query'], $statement['bindings']);
        } finally {
            DB::statement('reset enable_seqscan');
        }

        return implode("\n", array_map(static fn (object $row): string => (string) array_values((array) $row)[0], $rows));
    }
}
