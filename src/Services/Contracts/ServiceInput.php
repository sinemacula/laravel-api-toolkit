<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Minimal service input contract.
 *
 * Kept to toArray() so spatie/laravel-data objects satisfy it without a
 * dependency on that package.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceInput
{
    /**
     * Return the input as an associative array.
     *
     * Reaches lifecycle-event listeners verbatim, so it must leave out secrets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
