<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * The row goes to the model's connection, resolved now. Timestamps follow
     * the model and are taken at deferral; a full pool flushes, which may
     * throw.
     *
     * @param  array<string, mixed>  $attributes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function defer(array $attributes): void
    {
        $model = $this->getModel();

        $this->writePool->add($model->getConnectionName() ?? DB::getDefaultConnection(), $model->getTable(), $this->withDeferralTimestamps($model, $attributes));
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

    /**
     * Fill the model's timestamp columns the attributes leave out.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withDeferralTimestamps(Model $model, array $attributes): array
    {
        if (!$model->usesTimestamps()) {
            return $attributes;
        }

        $now = $model->freshTimestampString();

        foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {

            if ($column === null || array_key_exists($column, $attributes)) {
                continue;
            }

            $attributes[$column] = $now;
        }

        return $attributes;
    }
}
