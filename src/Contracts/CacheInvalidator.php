<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Cache invalidation contract.
 *
 * Implemented by both the per-query CacheStore and the reference-mode
 * ReferenceCache so a repository can invalidate its table cache without
 * depending on which store strategy is currently active.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface CacheInvalidator
{
    /**
     * Invalidate every cached entry for the repository table.
     *
     * @return void
     */
    public function flushTable(): void;
}
