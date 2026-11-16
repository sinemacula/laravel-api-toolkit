<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Runtime;

use Illuminate\Support\Facades\Config;

/**
 * Detects whether the process is actually serving under Octane or a queue
 * worker, not merely having the runtime installed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RuntimeContext
{
    /**
     * Determine whether the current process is being served under Octane.
     *
     * Only a booted Octane worker sets $_SERVER['LARAVEL_OCTANE'].
     *
     * @return bool
     */
    public function isServingUnderOctane(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Determine whether a job on the given connection runs in a real queue
     * worker, which is any connection with a non-sync driver.
     *
     * @param  string  $connection
     * @return bool
     */
    public function isServingAsQueueWorker(string $connection): bool
    {
        $driver = Config::get("queue.connections.{$connection}.driver");

        if (!is_string($driver) || $driver === '') {
            return false;
        }

        return $driver !== 'sync';
    }
}
