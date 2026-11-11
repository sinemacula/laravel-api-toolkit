<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;

/**
 * The identity of the schema a connection reads.
 *
 * The name alone is not enough: a tenancy switcher can repoint one name.
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
            self::endpoint($connection),
        ]));
    }

    /**
     * Return the server the connection is configured to reach.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, mixed>
     */
    private static function endpoint(Connection $connection): array
    {
        return [
            'driver'               => $connection->getConfig('driver'),
            'host'                 => self::hosts($connection->getConfig('host')),
            'port'                 => self::port($connection->getConfig('port')),
            'unix_socket'          => $connection->getConfig('unix_socket') ?: null,
            'connect_via_database' => $connection->getConfig('connect_via_database'),
            'connect_via_port'     => self::port($connection->getConfig('connect_via_port')),
            'odbc'                 => $connection->getConfig('odbc') === true,
            'odbc_datasource_name' => $connection->getConfig('odbc_datasource_name'),
        ];
    }

    /**
     * Return the configured hosts as a sorted list of distinct names.
     *
     * @param  mixed  $hosts
     * @return list<string>
     */
    private static function hosts(mixed $hosts): array
    {
        $names = [];

        foreach (Arr::wrap($hosts) as $host) {
            if ($host === '' || (!is_string($host) && !is_int($host))) {
                continue;
            }

            $names[] = (string) $host;
        }

        $names = array_unique($names);

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Normalise a configured string or integer port to a string.
     *
     * @param  mixed  $port
     * @return string|null
     */
    private static function port(mixed $port): ?string
    {
        return is_scalar($port) ? (string) $port : null;
    }
}
