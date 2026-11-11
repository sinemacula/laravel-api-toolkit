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
     * Determine whether the current process is serving as a real queue worker.
     *
     * Without a connection this checks for a queue:work or queue:listen
     * command; with a job's connection it checks for a non-sync driver.
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
     * Determine whether the current process is running a queue worker command.
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
