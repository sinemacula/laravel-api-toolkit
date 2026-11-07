<?php

namespace Tests\Fixtures\Services;

use Closure;
use Throwable;

/**
 * Database manager that logs the transactions it runs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class FakeDatabaseManager
{
    /** @var list<int> The attempts each transaction was given */
    public array $attempts = [];

    /**
     * Constructor.
     *
     * @param  \Tests\Fixtures\Services\ServiceLog  $log
     */
    public function __construct(private readonly ServiceLog $log)
    {
    }

    /**
     * Run the callback inside a logged transaction.
     *
     * @param  \Closure  $callback
     * @param  int  $attempts
     * @return mixed
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        $this->attempts[] = $attempts;
        $this->log->add('begin');

        try {
            $result = $callback($this);
        } catch (Throwable $exception) {
            $this->log->add('rollback');
            throw $exception;
        }

        $this->log->add('commit');

        return $result;
    }
}
