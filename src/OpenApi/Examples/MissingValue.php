<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

/**
 * Marks a schema node the generator found no value for.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class MissingValue
{
    /**
     * Constructor.
     *
     * @param  string  $pointer
     * @param  bool  $undocumented
     */
    public function __construct(

        /** The JSON pointer of the node without a value */
        public string $pointer,

        /** Whether the node is undocumented rather than unsatisfied */
        public bool $undocumented = false,
    ) {}
}
