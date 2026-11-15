<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;

/**
 * A documentable route, resolved once and shared by every audience it joins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocumentedRoute
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  string  $path
     * @param  non-empty-list<string>  $verbs
     * @param  array{model: class-string<\Illuminate\Database\Eloquent\Model>, resource: class-string, schema: string}|null  $resource
     * @param  string  $tag
     * @param  array<string, string>  $operationIds
     * @param  string|null  $summary
     */
    public function __construct(

        /** The route being documented. */
        public Route $route,

        /** The controller handling the route, or null for a closure. */
        public ?string $controllerClass,

        /** The controller method the route dispatches to. */
        public string $action,

        /** The OpenAPI path template of the route's URI. */
        public string $path,

        /** The documentable, lowercased HTTP verbs of the route. */
        public array $verbs,

        /** The resource the action serves, if it is a resource action. */
        public ?array $resource,

        /** The tag the route's operations are grouped under. */
        public string $tag,

        /** The operationId of each verb; empty while operation ids are off. */
        public array $operationIds,

        /** The summary the action declares, if any. */
        public ?string $summary,
    ) {}

    /**
     * Return the first verb's operationId, which every verb's description falls
     * back to.
     *
     * @return string|null
     */
    public function primaryOperationId(): ?string
    {
        return $this->operationIds[$this->verbs[0]] ?? null;
    }

    /**
     * Describe the operation under the verb for a failure message, prefixing
     * the path with the route's domain, if any.
     *
     * @param  string  $verb
     * @return string
     */
    public function label(string $verb): string
    {
        $handler = $this->controllerClass === null
            ? $this->route->getActionName()
            : $this->controllerClass . '::' . $this->action;

        return sprintf('%s %s%s (%s)', strtoupper($verb), $this->route->getDomain(), $this->path, $handler);
    }
}
