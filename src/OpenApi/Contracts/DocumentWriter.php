<?php

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Outbound output port for persisting the assembled OpenAPI document.
 *
 * The single io seam in the entire exporter feature: every other unit is a pure
 * transformer or a read-only metadata/schema query. Adapters persist the
 * serialized document to their backing store.
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
