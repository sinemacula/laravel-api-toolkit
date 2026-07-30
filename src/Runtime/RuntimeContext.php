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
     * With no `$connection` this is the boot-time check: the process is a
     * worker only when it is running the `queue:work`/`queue:listen` command,
     * mirroring how the Octane check keys on a real serving marker rather than
     * mere installation - a web request whose default queue merely happens to
     * be non-`sync` is not a worker. With a `$connection` (a fired job event's
     * connection name) it returns true only when that driver is a non-`sync`
     * string; the `sync` driver runs jobs inside the dispatching request and is
     * not a real worker boundary.
     *
     * @param  string|null  $connection
     * @return bool
     */
    public function isServingAsQueueWorker(?string $connection = null): bool
    {
        if ($connection === null) {
            return $this->isRunningQueueWorkerCommand();
        }

        $driver = Config::get("queue.connections.{$connection}.driver");

        if (!is_string($driver) || $driver === '') {
            return false;
        }

        return $driver !== 'sync';
    }

    /**
     * Determine whether the current process is executing a queue worker
     * command, the boot-time signal that this process is an actual worker.
     *
     * @return bool
     */
    private function isRunningQueueWorkerCommand(): bool
    {
        $argv = $_SERVER['argv'] ?? null;

        if (!is_array($argv)) {
            return false;
        }

        return in_array('queue:work', $argv, true) || in_array('queue:listen', $argv, true);
    }
}
