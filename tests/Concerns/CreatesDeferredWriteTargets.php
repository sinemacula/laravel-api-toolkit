<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Repositories\DeferrableModelRepository;

/**
 * Creates the tables, the second connection and the collaborators deferred
 * writes are tested against.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait CreatesDeferredWriteTargets
{
    /**
     * Create the default-connection tables of the timestamp fixture models.
     *
     * @return void
     */
    protected function createTimestampFixtureTables(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->timestamp('created')->nullable();
            $table->timestamp('modified')->nullable();
        });

        Schema::create('readings', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('updated_at')->nullable();
        });
    }

    /**
     * Configure the second in-memory connection and its audit table.
     *
     * Configured after the test transaction opens, so the connection reports
     * the transactions it runs to the test's transaction manager.
     *
     * @return void
     */
    protected function useAuditConnection(): void
    {
        Config::set('database.connections.audit', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('audit');

        Schema::connection('audit')->create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('action');
            $table->timestamps();
        });
    }

    /**
     * Build a deferrable repository over the given model class.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return \Tests\Fixtures\Repositories\DeferrableModelRepository
     */
    protected function deferrableRepository(string $model): DeferrableModelRepository
    {
        $app = $this->app;

        assert($app instanceof Application);

        return new DeferrableModelRepository($app, $model);
    }

    /**
     * Get the transaction tracker the test's connections report to.
     *
     * @return \Illuminate\Database\DatabaseTransactionsManager
     */
    protected function transactionTracker(): DatabaseTransactionsManager
    {
        $transactions = $this->app?->make('db.transactions');

        assert($transactions instanceof DatabaseTransactionsManager);

        return $transactions;
    }
}
