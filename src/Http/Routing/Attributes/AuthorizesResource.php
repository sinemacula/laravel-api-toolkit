<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing\Attributes;

/**
 * Declare the resource an authorized controller guards.
 *
 * Placed on an AuthorizedController subclass, this attribute drives the
 * resource authorization middleware in place of the framework's
 * authorizeResource() call: the named model is authorized against the gate for
 * each REST action, bound to the given route parameter, with the listed actions
 * left ungated. When no parameter is given the snake-cased model basename is
 * used; an explicit parameter is used verbatim, so it must match the registered
 * route parameter name.
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

        /** The route parameter bound to the model, or null to derive it. */
        public ?string $parameter = null,

        /** The resource actions left outside the authorization guard. */
        public array $except = [],
    ) {}
}
