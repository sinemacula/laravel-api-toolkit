<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Runs queued jobs through a real worker on a private database queue.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait UsesDatabaseQueue
{
    /**
     * Give the database queue a private connection, apart from the suite's, and
     * dispatch the command events a worker dispatches in production.
     *
     * @return void
     */
    protected function useDatabaseQueue(): void
    {
        Config::set('database.connections.queue_probe', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', ['driver' => 'database', 'connection' => 'queue_probe', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        Config::set('queue.failed', ['driver' => 'database-uuids', 'database' => 'queue_probe', 'table' => 'failed_jobs']);

        Schema::connection('queue_probe')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::connection('queue_probe')->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $kernel = $this->app->make(KernelContract::class); // @phpstan-ignore method.nonObject

        assert($kernel instanceof Kernel);

        $kernel->rerouteSymfonyCommandEvents();
    }

    /**
     * Process the next job on the database queue, allowing the given number of
     * attempts, then drop the scoped instances as the exiting process would.
     *
     * @param  int  $tries
     * @return void
     */
    protected function workOnce(int $tries = 2): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => $tries, '--timeout' => 0]);

        $this->app->forgetScopedInstances(); // @phpstan-ignore method.nonObject
    }

    /**
     * Run a daemon worker until the database queue is empty, retrying a failed
     * attempt at once, or until it has processed the given number of jobs.
     *
     * @param  int  $tries
     * @param  int  $maxJobs
     * @return void
     */
    protected function workUntilEmpty(int $tries = 2, int $maxJobs = 0): void
    {
        Artisan::call('queue:work', [
            'connection'        => 'database',
            '--stop-when-empty' => true,
            '--sleep'           => 0,
            '--backoff'         => 0,
            '--max-jobs'        => $maxJobs,
            '--memory'          => 0,
            '--tries'           => $tries,
            '--timeout'         => 0,
        ]);
    }
}
