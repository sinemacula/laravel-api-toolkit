<?php

namespace SineMacula\ApiToolkit\Logging;

use Monolog\Logger;

/**
 * Custom Monolog logger for database logging.
 *
 * This class creates a Monolog instance with a custom DatabaseHandler to store
 * logs in the database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DatabaseLogger
{
    /**
     * Invoke the custom logger instance.
     *
     * The config parameter is unused but required by Laravel's custom
     * logger factory contract.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  array<string, mixed>  $config
     * @return \Monolog\Logger
     */
    public function __invoke(array $config): Logger
    {
        return new Logger('database', [
            new DatabaseHandler,
        ]);
    }
}
