<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Reads what a connection knows about its indexes beyond the shared catalogue.
 *
 * Never cached, since the engine state it reads changes without a migration.
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
     * A prefix key part is reported as not holding its column's order.
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
            . 'max(case when seq_in_index = 1 and column_name is null then 1 else 0 end) as expressed, '
            . 'max(case when seq_in_index = 1 and sub_part is not null then 1 else 0 end) as unordered '
            . 'from information_schema.statistics '
            . 'where table_schema = coalesce(?, schema()) and table_name = ? '
            . 'group by lower(index_name)',
            $this->qualify($table, $connection),
        ));
    }

    /**
     * Report what an engine keeping an index catalogue says about the table.
     *
     * The planner matches an index column only under an identical collation.
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
            . '(i.indkey[0] = 0)::int as expressed, '
            . '(am.amname = \'btree\' and i.indkey[0] <> 0 and '
            . 'not exists (select 1 from pg_opclass d where d.opcdefault and d.opcmethod = oc.opcmethod '
            . 'and d.opcintype = oc.opcintype and d.opcfamily = oc.opcfamily))::int as unordered, '
            . '(i.indkey[0] <> 0 and i.indcollation[0] <> 0 and i.indcollation[0] <> a.attcollation)::int as recollated '
            . 'from pg_index i '
            . 'join pg_class c on c.oid = i.indrelid '
            . 'join pg_namespace n on n.oid = c.relnamespace '
            . 'join pg_class ic on ic.oid = i.indexrelid '
            . 'join pg_am am on am.oid = ic.relam '
            . 'left join pg_opclass oc on oc.oid = i.indclass[0] '
            . 'left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = i.indkey[0] '
            . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
            $this->qualify($table, $connection),
        ));
    }

    /**
     * Report what an engine answering through pragmas says about the table.
     *
     * Order and recollation go unreported; columns expose no collation here.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    private function fromPragmas(string $table, Connection $connection): IndexEligibility
    {
        [$schema, $name] = $this->qualify($table, $connection);

        // Both pragmas need the schema or attached tables resolve against main
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
     * Read the engine's rows into a report, or an empty one if the read fails.
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

        $facts = ['disregarded' => [], 'restricted' => [], 'expressed' => [], 'unordered' => [], 'recollated' => []];

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

        return new IndexEligibility(
            $facts['disregarded'],
            $facts['restricted'],
            $facts['expressed'],
            $facts['unordered'],
            $facts['recollated'],
        );
    }

    /**
     * Determine whether the engine answered a flag in the affirmative.
     *
     * @param  mixed  $flag
     * @return bool
     */
    private function reported(mixed $flag): bool
    {
        return is_numeric($flag) && (int) $flag === 1;
    }

    /**
     * Split a table reference into its schema, if any, and the prefixed table.
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
