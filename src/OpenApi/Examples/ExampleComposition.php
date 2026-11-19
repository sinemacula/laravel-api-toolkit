<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

/**
 * Immutable result of composing a document's examples.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExampleComposition
{
    /**
     * Constructor.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $skipped
     */
    public function __construct(

        /** The document with an example for every body that takes one */
        public array $document,

        /** The reason each body that takes an example has none */
        public array $skipped,
    ) {}
}
