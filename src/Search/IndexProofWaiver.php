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
 * The names read here are connection names, as the application's own database
 * configuration keys them, and not the engines behind them. A stock application
 * names each connection after the engine it speaks, which is why the shipped
 * entry reads as an engine, but an application naming its connections for
 * itself waives one of them without waiving every connection on the same
 * engine.
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
     * Determine whether the named connection waives the index proof.
     *
     * A connection reporting no name of its own is waived by nothing: the one
     * control that switches the proof off is a list of names, and a connection
     * there is no way to name cannot appear on it.
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
