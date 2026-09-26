<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Connection;

/**
 * The identity of the schema a connection reads.
 *
 * A connection name alone does not identify a schema. A tenancy switcher keeps
 * one name and points it at another database, prefix, or search path between
 * requests or jobs, so schema metadata keyed by the name alone would serve one
 * tenant's catalogue to another. The identity carries the name alongside the
 * effective database, table prefix, search path, and user, so each distinct
 * schema is cached apart while every process reading the same one shares its
 * entries. The user is there because a search path may name the schema after
 * whoever connects, and the name keeps its read or write suffix because a read
 * alias describes its write connection while reading from another server.
 *
 * The identity describes the connection object the read actually runs on, as
 * the framework built it, so a resolver that hands out a different connection
 * under the same name, read and write overrides, and URL configuration are all
 * reflected. Describing it runs no query, since the framework opens the
 * underlying database handle only when a statement first needs it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaIdentity
{
    /**
     * Return the identity of the schema the given connection reads.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return string
     */
    public static function of(Connection $connection): string
    {
        $name = $connection->getNameWithReadWriteType() ?? '';

        return $name . '@' . hash('xxh128', serialize([
            $name,
            $connection->getDatabaseName(),
            $connection->getTablePrefix(),
            $connection->getConfig('search_path'),
            $connection->getConfig('schema'),
            $connection->getConfig('username'),
        ]));
    }
}
