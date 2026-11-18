<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Persists the assembled OpenAPI documents.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DocumentWriter
{
    /**
     * Persist every serialized document at its path, or none of them.
     *
     * @param  array<string, string>  $documents
     * @return void
     *
     * @throws \RuntimeException
     */
    public function write(array $documents): void;
}
