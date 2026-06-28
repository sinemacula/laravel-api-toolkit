<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Runtime;

use Illuminate\Support\Facades\Config;

/**
 * Detects whether the current process is actually serving under a long-lived
 * worker runtime (Octane or a real queue worker), as opposed to one where the
 * runtime is merely installed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RuntimeContext
{
    /**
     * Determine whether the current process is being served under Octane.
     *
     * Returns true only when `$_SERVER['LARAVEL_OCTANE']` is set. A booted
     * Octane worker sets this key; merely having laravel/octane installed under
     * php-fpm does not.
     *
     * @return bool
     */
    public function isServingUnderOctane(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Determine whether the current process is serving as a real queue worker.
     *
     * When `$connection` is null, the default queue connection is resolved from
     * config. Returns true only when the resolved driver is a non-`sync`
     * string. The `sync` driver runs jobs inside the dispatching HTTP request
     * and is therefore not a real worker boundary.
     *
     * @param  string|null  $connection
     * @return bool
     */
    public function isServingAsQueueWorker(?string $connection = null): bool
    {
        if ($connection === null) {
            $default = Config::get('queue.default');

            if (!is_string($default) || $default === '') {
                return false;
            }

            $connection = $default;
        }

        $driver = Config::get("queue.connections.{$connection}.driver");

        if (!is_string($driver) || $driver === '') {
            return false;
        }

        return $driver !== 'sync';
    }
}
