<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Provides opt-in deferred bulk inserts for API repositories.
 *
 * Unflushed writes live only in memory and are lost if the process dies.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Deferrable
{
    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool */
    private WritePool $writePool;

    /**
     * Defer an insert operation to the write pool.
     *
     * Timestamps are taken at deferral; a full pool flushes, which may throw.
     *
     * @param  array<string, mixed>  $attributes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     */
    public function flushWrites(): WritePoolFlushResult
    {
        return $this->writePool->flush();
    }

    /**
     * Boot the deferrable concern.
     *
     * Booted via Repository::bootConcerns() so it composes with Cacheable.
     *
     * @return void
     */
    protected function bootDeferrable(): void
    {
        $this->writePool = $this->app->make(WritePool::class);
    }
}
