<?php

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Contract for traits that need to run setup logic during service
 * construction.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Initializable
{
    /**
     * Initialize the trait within the service context.
     *
     * Called during service construction, after payload normalization.
     * Implementations should set up internal state required by the trait.
     * Exceptions thrown here will propagate to the caller constructing
     * the service.
     *
     * @return void
     */
    public static function initializeTrait(): void;
}
