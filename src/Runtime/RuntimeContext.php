<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Runtime;

use Illuminate\Support\Facades\Config;

/**
 * Detects whether the process is actually serving under Octane or a queue
 * worker, not merely having the runtime installed, and which console command it
 * is running.
 *
 * The started commands are process-static, so an application booted while a
 * command runs, such as the one config:cache builds, sees that command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class RuntimeContext
{
    /** @var list<string> The console commands that serve queue jobs */
    private const array WORKER_COMMANDS = ['queue:work', 'queue:listen', 'horizon:work'];

    /** @var list<string|null> The commands started in this process, innermost last */
    private static array $commands = [];

    /**
     * Record that the given command has started.
     *
     * @param  string|null  $command
     * @return void
     */
    public static function enterCommand(?string $command): void
    {
        self::$commands[] = $command;
    }

    /**
     * Record that the given command has finished, when it is the innermost one
     * started.
     *
     * @param  string|null  $command
     * @return void
     */
    public static function leaveCommand(?string $command): void
    {
        if (end(self::$commands) !== $command) {
            return;
        }

        array_pop(self::$commands);
    }

    /**
     * Forget every started command.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$commands = [];
    }

    /**
     * Return the console command being run: the innermost one started in this
     * process, else the one named on the command line.
     *
     * @return string|null
     */
    public function command(): ?string
    {
        return self::$commands === [] ? $this->commandFromArgv() : self::$commands[array_key_last(self::$commands)];
    }

    /**
     * Determine whether the command being run serves queue jobs.
     *
     * @return bool
     */
    public function isRunningQueueWorker(): bool
    {
        return in_array($this->command(), self::WORKER_COMMANDS, true);
    }

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
     * worker, which is any connection whose driver does not run the job inside
     * the current request.
     *
     * A worker started without a connection name runs the default one. The
     * deferred driver runs the job after the response, in the same process.
     *
     * @param  string|null  $connection
     * @return bool
     */
    public function isServingAsQueueWorker(?string $connection): bool
    {
        $connection ??= Config::get('queue.default');

        $driver = is_string($connection) ? Config::get("queue.connections.{$connection}.driver") : null;

        if (!is_string($driver) || $driver === '') {
            return false;
        }

        return !in_array($driver, ['sync', 'deferred'], true);
    }

    /**
     * Return the command named on the command line, the first argument that is
     * not an option, skipping a space-separated --env value.
     *
     * @return string|null
     */
    private function commandFromArgv(): ?string
    {
        /** @var array<int, string> $argv */
        $argv      = $_SERVER['argv'] ?? [];
        $arguments = array_slice($argv, 1);

        while ($arguments !== []) {

            $argument = array_shift($arguments);

            if ($argument === '--env') {
                array_shift($arguments);
            } elseif (!str_starts_with($argument, '-')) {
                return $argument;
            }
        }

        return null;
    }
}
