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
 * The index proof behind a declared search surface, as the request path asks
 * for it.
 *
 * The proof reads the connection's catalogue, which is why schema validation
 * runs it in a build. It is asked again on the request path because that build
 * step is optional and disabled in production by default, and on one supported
 * engine a missing index is not an error at all: the predicate stays legal and
 * the request quietly reads the whole table, which is the outcome the whole
 * search surface exists to remove. Asking here turns that into a refused
 * request naming the missing index.
 *
 * The answer is keyed by everything that could change it - the schema identity
 * of the connection, its driver, the table, the strategy, the columns declared
 * with it, and the shortest word a term may carry - and shared through the
 * metadata store under a short fixed expiry, so every process serving the same
 * schema pays the catalogue reads once per expiry rather than once per request.
 * The expiry is what bounds an index changed outside a migration: one created,
 * dropped, or made unusable by hand is reflected once it lapses, while a
 * migration or an explicit invalidation retires every stored answer at once. An
 * expiry of zero keeps the answer for one operation only.
 *
 * Within one operation the answer is also held in process, and that copy is
 * cleared at every lifecycle boundary, so one long operation may keep an answer
 * past the expiry.
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
     * Return the proof held in the shared store under the key, taking it when
     * none is held or sharing is switched off.
     *
     * @param  string  $key
     * @param  callable(): array<int, string>  $prove
     * @return array<int, string>
     */
    private function share(string $key, callable $prove): array
    {
        $ttl = self::ttl();

        // A store forgets rather than keeps a value written with no lifetime.
        if ($ttl === 0) {
            return $prove();
        }

        /** @var array<int, string> */
        return $this->metadataCacheWriter->rememberMetadata($key, $prove, $ttl);
    }

    /**
     * Return the key every part of the declaration that could change the proof
     * is folded into.
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
                $connection->getDriverName(),
                $driver::class,
                $table,
                $strategy->value,
                $columns,
                SearchTerm::minimumWordLength(),
            ])),
        ]);
    }

    /**
     * Return the seconds an answer is shared for, falling back to the default
     * when the configured value is not numeric or is negative.
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
