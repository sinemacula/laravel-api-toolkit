<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

/**
 * One URL shape a route matches, as an OpenAPI path template whose parameters
 * are all required.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RoutePathForm
{
    /**
     * Constructor.
     *
     * @param  string  $path
     * @param  list<string>  $parameters
     * @param  list<string>  $optional
     * @param  bool  $full
     */
    public function __construct(

        /** The OpenAPI path template of the shape. */
        public string $path,

        /** The path parameters of the template, in template order. */
        public array $parameters,

        /** The optional parameters the shape includes, in template order. */
        public array $optional,

        /** Whether the shape includes every optional parameter. */
        public bool $full,
    ) {}
}
