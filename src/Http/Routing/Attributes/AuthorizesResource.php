<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing\Attributes;

/**
 * Declare the resource an authorized controller guards.
 *
 * An explicit parameter is used verbatim, so it must match the route's name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AuthorizesResource
{
    /**
     * Create a new resource authorization binding.
     *
     * @param  class-string  $model
     * @param  string|null  $parameter
     * @param  array<int, string>  $except
     */
    public function __construct(

        /** The model class the controller's resource actions authorize. */
        public string $model,

        /** The route parameter bound to the model, or null for its snake-cased basename. */
        public ?string $parameter = null,

        /** The resource actions left outside the authorization guard. */
        public array $except = [],
    ) {}
}
