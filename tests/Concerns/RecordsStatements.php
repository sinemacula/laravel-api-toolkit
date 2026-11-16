<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Records the statements a block of work runs and asserts how many there were.
 *
 * The listener is detached once the block returns or throws, so nothing that
 * runs afterwards is recorded.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RecordsStatements
{
    /**
     * Run the callback and return the SQL of every statement it ran.
     *
     * @param  \Closure(): mixed  $callback
     * @return list<string>
     */
    protected function recordStatements(\Closure $callback): array
    {
        $events = DB::connection()->getEventDispatcher();

        Assert::assertInstanceOf(Dispatcher::class, $events);

        $statements = [];
        $listener   = static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        };

        $events->listen(QueryExecuted::class, $listener);

        try {
            $callback();
        } finally {
            self::detachListener($events, $listener);
        }

        return $statements;
    }

    /**
     * Assert the number of statements recorded, printing them on failure.
     *
     * @param  int  $expected
     * @param  list<string>  $statements
     * @return void
     */
    protected static function assertStatementCount(int $expected, array $statements): void
    {
        Assert::assertCount($expected, $statements, "The statements run were:\n" . implode("\n", $statements));
    }

    /**
     * Remove the listener, keeping every other one in its order.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     * @param  \Closure  $listener
     * @return void
     */
    private static function detachListener(Dispatcher $events, \Closure $listener): void
    {
        /** @var array<int, mixed> $listeners */
        $listeners = $events->getRawListeners()[QueryExecuted::class] ?? [];

        $events->forget(QueryExecuted::class);

        foreach ($listeners as $other) {

            if ($other === $listener) {
                continue;
            }

            $events->listen(QueryExecuted::class, $other);
        }
    }
}
