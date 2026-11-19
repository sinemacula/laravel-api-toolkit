<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Contract for models whose stored state carries a version that every write
 * advances.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Versioned
{
    /**
     * Get the name of the column holding the version.
     *
     * @return string
     */
    public function getVersionColumn(): string;

    /**
     * Advance the version, so the next save writes it even when no column has
     * changed.
     *
     * @return void
     */
    public function markVersionChanged(): void;
}
