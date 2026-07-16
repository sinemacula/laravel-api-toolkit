<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Concerns;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Base recording concern that logs entry/exit order and transaction depth.
 *
 * Concrete subclasses supply a label; each records "<label>:before" before
 * delegating to the next stage and "<label>:after" after it returns, and
 * captures the active database transaction level. A shared static trace lets a
 * test assert the composition order and that every concern ran inside the one
 * transaction.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class RecordingConcern implements ServiceConcern
{
    /** @var array<int, string> Ordered entry/exit markers across all concerns */
    public static array $trace = [];

    /** @var array<string, int> Transaction level captured per label */
    public static array $levels = [];

    /**
     * Reset the shared static capture between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$trace  = [];
        self::$levels = [];
    }

    /**
     * Append an entry to the shared trace.
     *
     * @param  string  $entry
     * @return void
     */
    public static function push(string $entry): void
    {
        self::$trace[] = $entry;
    }

    /**
     * Record the transaction level captured under the given key.
     *
     * @param  string  $key
     * @param  int  $level
     * @return void
     */
    public static function recordLevel(string $key, int $level): void
    {
        self::$levels[$key] = $level;
    }

    /**
     * Record the label around the next stage and capture the transaction level.
     *
     * The context is required by the concern contract but not used here.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return mixed
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): mixed
    {
        $label = $this->label();

        self::push($label . ':before');
        self::recordLevel($label, DB::transactionLevel());

        $result = $next();

        self::push($label . ':after');

        return $result;
    }

    /**
     * Return the unique label for this concern.
     *
     * @return string
     */
    abstract protected function label(): string;
}
