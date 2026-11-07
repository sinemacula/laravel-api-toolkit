<?php

namespace Tests\Fixtures\Services\Concerns;

/**
 * Consumer trait that shares its name with the toolkit's lifecycle trait and
 * counts its initializer and success callback.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait HasServiceLifecycle
{
    /** @var int How many times the trait initializer ran */
    public static int $initializations = 0;

    /** @var int How many times the trait success callback ran */
    public int $successes = 0;

    /**
     * Count the trait initializer.
     *
     * @return void
     */
    protected static function initializeHasServiceLifecycle(): void
    {
        static::$initializations++;
    }

    /**
     * Count the trait success callback.
     *
     * @return void
     */
    protected function hasServiceLifecycleSuccess(): void
    {
        $this->successes++;
    }
}
