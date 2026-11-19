<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;

/**
 * Query plan suite for the MySQL engine.
 *
 * A secondary index carries the primary key after its own columns, so an index
 * on the ordered column alone already serves the appended key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PageOrder::class)]
#[CoversClass(RelationWindow::class)]
#[Group('mysql')]
final class MySqlQueryPlanTest extends EngineQueryPlanTestCase
{
    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'mysql';
    }

    /**
     * Assert that the engine reads the statement's rows in order from the
     * index, with no sort pass.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    #[\Override]
    protected function assertOrderedFrom(string $index, array $statement): void
    {
        $row = $this->usersRow($index, $statement);

        self::assertSame($index, $row['key'] ?? null);
        self::assertStringNotContainsString('Using filesort', self::text($row['Extra'] ?? null));
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
    }

    /**
     * Assert that the engine reads a range of the index from the cursor's
     * boundary, in order.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    #[\Override]
    protected function assertSeeks(string $index, array $statement): void
    {
        $this->assertOrderedFrom($index, $statement);

        self::assertSame('range', $this->usersRow($index, $statement)['type'] ?? null);
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
        $row = $this->row($this->explain($statement['query'], $statement['bindings']), 'posts');

        self::assertStringStartsWith('posts_user_id', self::text($row['key'] ?? null));
    }

    /**
     * Explain the statement with every index but the given one priced out, and
     * return the users table's row.
     *
     * Forcing the index asks whether it can serve the order, as disabling scans
     * does on the other engine: a deep page is cheaper to sort after a scan.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return array<string, mixed>
     */
    private function usersRow(string $index, array $statement): array
    {
        $query = preg_replace('/from `users`/', sprintf('from `users` force index (`%s`)', $index), $statement['query'], 1);

        self::assertIsString($query);

        return $this->row($this->explain($query, $statement['bindings']), 'users');
    }

    /**
     * Return the plan row reading the given table.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @param  string  $table
     * @return array<string, mixed>
     */
    private function row(array $plan, string $table): array
    {
        foreach ($plan as $row) {
            if (($row['table'] ?? null) === $table) {
                return $row;
            }
        }

        self::fail(sprintf('The plan reads no %s table: %s', $table, json_encode($plan)));
    }

    /**
     * Read a plan column the engine leaves null when it has nothing to say.
     *
     * @param  mixed  $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Return the engine's plan for the statement, one row per table read.
     *
     * @param  string  $query
     * @param  array<int, mixed>  $bindings
     * @return array<int, array<string, mixed>>
     */
    private function explain(string $query, array $bindings): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::select('explain ' . $query, $bindings));
    }
}
