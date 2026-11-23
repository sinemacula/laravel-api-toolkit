<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * The typed settings bounding what a request may query.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class QuerySettings
{
    /**
     * Return the largest page size a client may request, 0 for no ceiling.
     *
     * @return int
     */
    public static function maxLimit(): int
    {
        return ToolkitConfig::readCap('parser.max_limit');
    }

    /**
     * Return the page size of a request that names none.
     *
     * @return int
     */
    public static function defaultLimit(): int
    {
        return ToolkitConfig::readInteger('parser.defaults.limit', 1);
    }

    /**
     * Determine whether the random order keyword is honoured.
     *
     * @return bool
     */
    public static function canOrderRandomly(): bool
    {
        return ToolkitConfig::readBoolean('repositories.allow_random_order');
    }

    /**
     * Return the given query cost cap, 0 when it is disabled.
     *
     * @param  string  $cap
     * @return int
     */
    public static function costCap(string $cap): int
    {
        return ToolkitConfig::readCap('query_cost.' . $cap);
    }

    /**
     * Return the shortest word a search term may carry.
     *
     * @return int
     */
    public static function minWordLength(): int
    {
        return ToolkitConfig::readInteger('search.min_word_length', SearchTerm::MIN_WORD_LENGTH);
    }

    /**
     * Return the longest search term accepted.
     *
     * @return int
     */
    public static function maxSearchLength(): int
    {
        return ToolkitConfig::readInteger('search.max_length', 1);
    }

    /**
     * Return the most words a search term may carry.
     *
     * @return int
     */
    public static function maxSearchWords(): int
    {
        return ToolkitConfig::readInteger('search.max_words', 1);
    }

    /**
     * Return the seconds a request-time index proof is shared for.
     *
     * @return int
     */
    public static function indexProofTtl(): int
    {
        return ToolkitConfig::readInteger('search.index_proof_ttl', 0);
    }

    /**
     * Return the connections allowed to serve a search no index is proved for.
     *
     * @return list<string>
     */
    public static function unverifiedConnections(): array
    {
        return ToolkitConfig::readList('search.unverified_connections');
    }
}
