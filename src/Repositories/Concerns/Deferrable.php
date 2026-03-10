<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Provides opt-in deferred write capability for API repositories.
 *
 * When used by an ApiRepository subclass, this trait allows insert
 * operations to be collected in memory and flushed as bulk INSERT
 * statements at the end of the request lifecycle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Deferrable
{
    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool The write pool collaborator instance. */
    private WritePool $writePool;

    /**
     * Defer an insert operation to the write pool.
     *
     * The attributes are buffered in memory and flushed as a bulk
     * INSERT at the end of the request lifecycle. Timestamps are
     * captured at the time of deferral, not at flush time.
     *
     * @param  array<string, mixed>  $attributes
     * @return void
     */
    public function defer(array $attributes): void
    {
        $table = $this->getModel()->getTable();
        $now   = now()->toDateTimeString();

        $attributes['created_at'] ??= $now;
        $attributes['updated_at'] ??= $now;

        $this->writePool->add($table, $attributes);
    }

    /**
     * Manually flush all deferred writes to the database.
     *
     * @return void
     */
    public function flushWrites(): void
    {
        $this->writePool->flush();
    }

    /**
     * Boot the repository instance.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        parent::boot();

        $this->writePool = $this->app->make(WritePool::class);
    }
}
