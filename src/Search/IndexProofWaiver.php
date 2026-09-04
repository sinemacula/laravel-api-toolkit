<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Support\Facades\Config;

/**
 * Reads the connections on which the index proof behind a search is waived.
 *
 * A driver that cannot inspect its connection has proved nothing about the
 * indexes behind a declared strategy, and a search it serves may be reading the
 * whole table. That is refused everywhere except the connections named here,
 * which are meant to be the development connection a suite runs against rather
 * than anything serving traffic.
 *
 * The list is read in two places - where a request is refused and where the
 * schema is validated - and both have to agree, so it is read in one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class IndexProofWaiver
{
    /**
     * Determine whether the connection waives the index proof.
     *
     * @param  string  $connection
     * @return bool
     */
    public static function waives(string $connection): bool
    {
        $waived = Config::get('api-toolkit.search.unverified_connections', []);

        return is_array($waived) && in_array($connection, $waived, true);
    }
}
