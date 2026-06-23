<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Decides whether a query result is small enough to be stored in the
 * repository cache.
 *
 * The guard bounds both the row count and the serialized byte size of a
 * result. A result that exceeds either bound is still fetched and returned to
 * the caller, but is never written to the cache, preventing unbounded growth.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheSizeGuard
{
    /**
     * Create a new cache size guard instance.
     *
     * @param  int|null  $maxRows
     * @param  int|null  $maxBytes
     * @return void
     */
    public function __construct(

        /** Maximum row count allowed in a stored result, or null to disable. */
        private ?int $maxRows,

        /** Maximum serialized byte size of a result, or null to disable. */
        private ?int $maxBytes,
    ) {}

    /**
     * Determine whether the given result may be stored.
     *
     * @param  mixed  $result
     * @param  int  $rows
     * @return bool
     */
    public function allows(mixed $result, int $rows): bool
    {
        if ($this->maxRows !== null && $rows > $this->maxRows) {
            return false;
        }

        return !($this->maxBytes !== null && strlen(serialize($result)) > $this->maxBytes);
    }
}
