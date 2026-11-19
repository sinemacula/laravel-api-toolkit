<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Concerns;

/**
 * Fixture model trait adding the posts to the default loads as the model is
 * built.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait LoadsPostsByDefault
{
    /**
     * Load the posts by default.
     *
     * @return void
     */
    protected function initializeLoadsPostsByDefault(): void
    {
        $this->with[] = 'posts';
    }
}
