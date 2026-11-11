<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Persists the assembled OpenAPI document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DocumentWriter
{
    /**
     * Persist the serialized document at the given path.
     *
     * @param  string  $path
     * @param  string  $contents
     * @return void
     *
     * @throws \RuntimeException
     */
    public function write(string $path, string $contents): void;
}
