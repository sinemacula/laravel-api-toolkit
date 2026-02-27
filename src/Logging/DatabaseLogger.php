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
class DatabaseLogger
{
    /**
     * Invoke the custom logger instance.
     *
     * @param  array<string, mixed>  $_config
     * @return \Monolog\Logger
     */
    public function __invoke(array $_config): Logger
    {
        return new Logger('database', [
            new DatabaseHandler,
        ]);
    }
}
