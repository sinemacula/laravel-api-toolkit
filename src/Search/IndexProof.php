<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIdentity;

/**
 * The index proof behind a declared search surface, asked on the request path.
 *
 * Asked per request because a missing index silently scans on some engines.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class IndexProof
{
    /** @var int The seconds an answer is shared for when none is configured */
    public const int DEFAULT_TTL = 60;

    /** @var string The config key holding the seconds an answer is shared for */
    public const string TTL_KEY = 'api-toolkit.search.index_proof_ttl';

    /** @var array<string, array<int, string>> */
    private static array $cache = [];

    /**
     * Create a new index proof instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataCacheWriter  $metadataCacheWriter
     * @return void
     */
    public function __construct(

        /** Shares each answer across operations under the metadata generation */
        private readonly MetadataCacheWriter $metadataCacheWriter,
    ) {}

    /**
     * Return every reason the strategy's columns are not index-served.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    public function defects(SearchDriver $driver, SearchStrategy $strategy, array $columns, string $table, Connection $connection): array
    {
        $key = self::key($driver, $strategy, $columns, $table, $connection);

        return self::$cache[$key] ??= $this->share($key, static fn (): array => self::flatten($driver->indexDefects($strategy, $columns, $table, $connection)));
    }

    /**
     * Clear the index proofs held for the current operation.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Return the shared proof under the key, taking it when none is held.
     *
     * @param  string  $key
     * @param  callable(): array<int, string>  $prove
     * @return array<int, string>
     */
    private function share(string $key, callable $prove): array
    {
        $ttl = self::ttl();

        // A cache write with no lifetime forgets the value
        if ($ttl === 0) {
            return $prove();
        }

        /** @var array<int, string> */
        return $this->metadataCacheWriter->rememberMetadata($key, $prove, $ttl);
    }

    /**
     * Return the cache key folding in everything that could change the proof.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return string
     */
    private static function key(SearchDriver $driver, SearchStrategy $strategy, array $columns, string $table, Connection $connection): string
    {
        return CacheKeys::SEARCH_INDEX_PROOF->resolveKey([
            SchemaIdentity::of($connection),
            hash('xxh128', serialize([
                $driver::class,
                $table,
                $strategy->value,
                $columns,
                SearchTerm::minimumWordLength(),
            ])),
        ]);
    }

    /**
     * Return the configured sharing TTL, or the default when it is invalid.
     *
     * @return int
     */
    private static function ttl(): int
    {
        $ttl = Config::get(self::TTL_KEY, self::DEFAULT_TTL);

        return is_numeric($ttl) && $ttl >= 0 ? (int) $ttl : self::DEFAULT_TTL;
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
