<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;

/**
 * Runs each test outside the suite's wrapping transaction, so a transaction the
 * test opens is the outermost one and after-commit callbacks follow production
 * semantics.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait CommitsRealTransactions
{
    /** @var array<int, string> No connection is wrapped, so the test owns level 1 */
    protected array $connectionsToTransact = [];

    /**
     * Give the container and the default connection one production transactions
     * manager.
     *
     * @return void
     */
    protected function setUpCommitsRealTransactions(): void
    {
        assert($this->app !== null);

        $manager = new DatabaseTransactionsManager;

        $this->app->instance('db.transactions', $manager);

        DB::connection()->setTransactionManager($manager);
    }

    /**
     * Roll back a transaction a failed test or commit left open.
     *
     * @return void
     */
    protected function tearDownCommitsRealTransactions(): void
    {
        $pdo = DB::connection()->getPdo();

        if (!$pdo->inTransaction()) {
            return;
        }

        $pdo->rollBack();
    }
}
