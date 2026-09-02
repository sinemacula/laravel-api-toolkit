<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Database\Connection;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Per-process memo of the index proof behind a declared search surface.
 *
 * The proof reads the connection's catalogue, which is why schema validation
 * runs it in a build. It is asked again on the request path because that build
 * step is optional and disabled in production by default, and on one supported
 * engine a missing index is not an error at all: the predicate stays legal and
 * the request quietly reads the whole table, which is the outcome the whole
 * search surface exists to remove. Asking once per worker process turns that
 * into a refused request naming the missing index.
 *
 * The answer is keyed by everything that could change it - the connection, its
 * driver, the table, the strategy, and the columns declared with it - and held
 * for the life of the process alongside the other schema-derived caches, so a
 * catalogue read is paid once rather than per search.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class IndexProof
{
    /** @var array<string, array<int, string>> */
    private static array $cache = [];

    /**
     * Return every reason the columns declared with the strategy are not served
     * from an index on this connection.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    public static function defects(SearchDriver $driver, SearchStrategy $strategy, array $columns, string $table, Connection $connection): array
    {
        $key = implode('|', [
            $connection->getName() ?? '',
            $connection->getDriverName(),
            $table,
            $strategy->value,
            implode(',', $columns),
        ]);

        return self::$cache[$key] ??= self::flatten($driver->indexDefects($strategy, $columns, $table, $connection));
    }

    /**
     * Clear the memoised index proofs.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Reduce the per-column defect map to the distinct reasons it carries.
     *
     * @param  array<string, array<int, string>>  $defects
     * @return array<int, string>
     */
    private static function flatten(array $defects): array
    {
        $flattened = [];

        foreach ($defects as $reasons) {

            foreach ($reasons as $reason) {

                if (in_array($reason, $flattened, true)) {
                    continue;
                }

                $flattened[] = $reason;
            }
        }

        return $flattened;
    }
}
