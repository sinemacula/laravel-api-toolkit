<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Reads back what a connection knows about its own indexes beyond the shared
 * catalogue.
 *
 * The catalogue names an index, the columns it covers and the kind it is, and
 * stops there. Whether the engine would actually plan against it, and whether
 * the columns it reports are the whole story, are answered by the engine's own
 * tables, and every reader that proves something from an index needs the same
 * two answers. They are read here so no reader carries engine knowledge of its
 * own and none of them can drift from another.
 *
 * An engine this cannot question reports nothing, which leaves every proof as
 * strict as it was. A question that fails is read the same way: an index proof
 * is not the place to turn a broken auxiliary read into a refusal, and the
 * catalogue itself is still worth reading. Each engine answers only the facts
 * it holds, and a fact an engine has no notion of is simply never reported.
 *
 * Nothing read here is cached. Every answer describes engine state that changes
 * without a migration, so a remembered one would outlive its truth.
 *
 * An application serving an engine this does not question can extend it to
 * answer for that engine, which is the whole of what a new engine needs.
 *
 * @inheritable
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class IndexEligibilityInspector
{
    /**
     * Report what the connection says about the table's indexes.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    public function inspect(string $table, Connection $connection): IndexEligibility
    {
        return match ($connection->getDriverName()) {
            'mysql'  => $this->fromStatistics($table, $connection),
            'pgsql'  => $this->fromCatalogue($table, $connection),
            'sqlite' => $this->fromPragmas($table, $connection),
            default  => new IndexEligibility,
        };
    }

    /**
     * Report what an engine keeping index statistics says about the table.
     *
     * An index held back from the planner is disregarded, and one whose first
     * key part is an expression rather than a column is reported as such. The
     * engine has no notion of an index over part of a table, so none is ever
     * restricted here.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    private function fromStatistics(string $table, Connection $connection): IndexEligibility
    {
        return $this->report(fn (): array => $connection->selectFromWriteConnection(
            'select lower(index_name) as name, '
            . 'max(case when is_visible = \'NO\' then 1 else 0 end) as disregarded, '
            . 'max(case when seq_in_index = 1 and column_name is null then 1 else 0 end) as expressed '
            . 'from information_schema.statistics '
            . 'where table_schema = coalesce(?, schema()) and table_name = ? '
            . 'group by lower(index_name)',
            $this->qualify($table, $connection),
        ));
    }

    /**
     * Report what an engine keeping an index catalogue says about the table.
     *
     * An index left behind by a build that never completed is disregarded, one
     * carrying a predicate holds only the rows that predicate admits, and one
     * whose first key entry names no column is keyed on an expression.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    private function fromCatalogue(string $table, Connection $connection): IndexEligibility
    {
        return $this->report(fn (): array => $connection->selectFromWriteConnection(
            'select lower(ic.relname) as name, '
            . '(not i.indisvalid)::int as disregarded, '
            . '(i.indpred is not null)::int as restricted, '
            . '(i.indkey[0] = 0)::int as expressed '
            . 'from pg_index i '
            . 'join pg_class c on c.oid = i.indrelid '
            . 'join pg_namespace n on n.oid = c.relnamespace '
            . 'join pg_class ic on ic.oid = i.indexrelid '
            . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
            $this->qualify($table, $connection),
        ));
    }

    /**
     * Report what an engine answering through pragmas says about the table.
     *
     * The engine holds an index back from no query, so none is ever
     * disregarded. It does answer the other two: an index carrying a predicate
     * holds only the rows that predicate admits, and one whose first key entry
     * names no column is keyed on an expression, which the aggregated column
     * list drops exactly as the other engines do.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    private function fromPragmas(string $table, Connection $connection): IndexEligibility
    {
        [$schema, $name] = $this->qualify($table, $connection);

        // Both pragmas take the schema, and neither defaults to the one the
        // reference names, so a table on an attached database would otherwise
        // be answered for by whatever carries its name on the main one.
        return $this->report(fn (): array => $connection->selectFromWriteConnection(
            'select lower(il.name) as name, '
            . '0 as disregarded, '
            . 'il.partial as restricted, '
            . '(select case when ii.name is null then 1 else 0 end '
            . 'from pragma_index_info(il.name, ?) ii where ii.seqno = 0) as expressed '
            . 'from pragma_index_list(?, ?) il',
            [$schema, $name, $schema],
        ));
    }

    /**
     * Read the rows an engine returns into a report, or report nothing where
     * the read failed.
     *
     * @param  callable(): array<int, mixed>  $read
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    private function report(callable $read): IndexEligibility
    {
        try {
            $rows = $read();
        } catch (QueryException) { // @phpstan-ignore catch.neverThrown
            return new IndexEligibility;
        }

        $facts = ['disregarded' => [], 'restricted' => [], 'expressed' => []];

        foreach ($rows as $row) {

            $entry = (array) $row;
            $name  = $entry['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            foreach (array_keys($facts) as $fact) {

                if (!$this->reported($entry[$fact] ?? null)) {
                    continue;
                }

                $facts[$fact][] = $name;
            }
        }

        return new IndexEligibility($facts['disregarded'], $facts['restricted'], $facts['expressed']);
    }

    /**
     * Determine whether the engine answered a flag in the affirmative.
     *
     * Every flag is cast to a number by the read that asks for it, so a value
     * arriving as text still compares as the number it spells rather than as a
     * non-empty string, which any label would.
     *
     * @param  mixed  $flag
     * @return bool
     */
    private function reported(mixed $flag): bool
    {
        return is_numeric($flag) && (int) $flag === 1;
    }

    /**
     * Split a table reference into the schema it names, where it names one, and
     * the prefixed table itself.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string|null>
     */
    private function qualify(string $table, Connection $connection): array
    {
        $segments = explode('.', $table);
        $name     = array_pop($segments);

        return [
            $segments === [] ? null : implode('.', $segments),
            $connection->getTablePrefix() . $name,
        ];
    }
}
