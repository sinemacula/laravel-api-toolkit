<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Minimal typed-input contract.
 *
 * Defines the smallest interface a service input must satisfy. Keeping the
 * contract to a single method allows spatie/laravel-data Data objects (which
 * already expose toArray()) to satisfy it without introducing a hard dependency
 * on that package.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceInput
{
    /**
     * Return the input as an associative array.
     *
     * This snapshot is carried on the service lifecycle events as inputSummary
     * and reaches event listeners verbatim. Override this method to scrub
     * sensitive keys before they are exposed to those listeners.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
