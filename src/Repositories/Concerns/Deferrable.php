<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Provides opt-in deferred write capability for API repositories.
 *
 * When used by an ApiRepository subclass, this trait allows insert operations
 * to be collected in memory and flushed as bulk INSERT statements at the end of
 * the request lifecycle.
 *
 * Durability window: deferred writes live only in PHP memory until the boundary
 * flush performed by the WritePoolFlushSubscriber on RequestHandled,
 * CommandFinished, JobProcessed, or JobFailed. A crash, out-of-memory
 * condition, or SIGKILL in that window loses any unflushed records. This is
 * inherent to in-memory deferral; for true durability use a real queue, which
 * is out of scope for this trait. Under the default collect strategy a failed
 * flush retains the records in the pool for the next boundary; use the log
 * strategy for fire-and-forget writes that may be dropped.
 *
 * Cache interaction: this concern coexists with Cacheable on the same
 * repository (each boots via its own boot{Concern} hook). Deferred writes are
 * persisted through the write pool's bulk INSERT, which bypasses the per-query
 * cache invalidation that fires on the repository's own write verbs. The
 * lifecycle-boundary flush compensates: it invalidates the per-query cache for
 * every persisted table (controlled by
 * `api-toolkit.deferred_writes.invalidate_query_cache`, on by default). This is
 * best-effort and covers default-config Cacheable repositories; a repository on
 * a custom cache store or key prefix is not reached, so call flushCache() after
 * the boundary flush, or rely on the TTL, for those.
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
     * The attributes are buffered in memory and flushed as a bulk INSERT at the
     * end of the request lifecycle. Timestamps are captured at the time of
     * deferral, not at flush time. When the pool reaches its limit an automatic
     * flush is triggered, which under the throw strategy may raise a
     * WritePoolFlushException from this call.
     *
     * @param  array<string, mixed>  $attributes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
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
     * Invoked by ApiRepository::bootConcerns() rather than overriding boot()
     * directly, so the concern can coexist with other bootable concerns (e.g.
     * Cacheable) without a fatal trait collision.
     *
     * @return void
     */
    protected function bootDeferrable(): void
    {
        $this->writePool = $this->app->make(WritePool::class);
    }
}
