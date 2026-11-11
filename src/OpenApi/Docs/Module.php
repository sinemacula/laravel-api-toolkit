<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

/**
 * Immutable descriptor for a documentation module.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class Module
{
    /**
     * Create a new module descriptor.
     *
     * @param  string  $key
     * @param  string  $name
     */
    public function __construct(

        /** The stable namespace prefix, e.g. "App\User". */
        public string $key,

        /** The display name, the last segment of the key, e.g. "User". */
        public string $name,
    ) {}
}
