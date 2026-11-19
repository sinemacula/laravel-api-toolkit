<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * The fields a child resource renders over an eager-loaded relation path.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ChildProjection
{
    /**
     * Create a new child projection.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  list<string>  $fields
     * @param  bool  $opaque
     */
    public function __construct(

        /** The child resource rendering the related models */
        public string $resource,

        /** Every field the child renders over the path, its fixed fields included */
        public array $fields,

        /** Whether something other than the child resource reads the related models */
        public bool $opaque,
    ) {}
}
