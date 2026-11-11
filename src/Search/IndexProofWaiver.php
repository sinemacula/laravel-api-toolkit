<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Support\Facades\Config;

/**
 * Reads the connections on which the index proof behind a search is waived.
 *
 * Entries are configured connection names, not the engines behind them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class IndexProofWaiver
{
    /**
     * Determine whether the named connection waives the index proof.
     *
     * @param  string|null  $connection
     * @return bool
     */
    public static function waives(?string $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        $waived = Config::get('api-toolkit.search.unverified_connections', []);

        return is_array($waived) && in_array($connection, $waived, true);
    }
}
