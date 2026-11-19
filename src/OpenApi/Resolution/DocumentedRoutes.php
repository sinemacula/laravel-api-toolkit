<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

/**
 * The documentable routes of every exported audience.
 *
 * Two routes may claim one path and verb; as in the built paths, the later
 * route owns the operation and the path and verb keep their first position. A
 * form omitting optional parameters only claims a path and verb left unclaimed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocumentedRoutes
{
    /**
     * Constructor.
     *
     * @param  array<array-key, list<\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>  $byAudience
     * @param  bool  $operationIdsEnabled
     */
    public function __construct(

        /** Each audience's routes in router collection order, shadowed ones included. */
        public array $byAudience,

        /** Whether operations carry an operationId. */
        public bool $operationIdsEnabled,
    ) {}

    /**
     * List the audience names the routes were collected for.
     *
     * @return list<string>
     */
    public function audiences(): array
    {
        return array_map('strval', array_keys($this->byAudience));
    }

    /**
     * List the audience's routes, shadowed ones included.
     *
     * @param  string  $audience
     * @return list<\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>
     */
    public function in(string $audience): array
    {
        return $this->byAudience[$audience] ?? [];
    }

    /**
     * Map each of the audience's exported operations to the route owning it.
     *
     * @param  string  $audience
     * @return array<string, array<string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>
     */
    public function operations(string $audience): array
    {
        $operations = [];

        foreach ($this->in($audience) as $route) {
            foreach ($route->verbs as $verb) {

                if (!$route->form->full && isset($operations[$route->form->path][$verb])) {
                    continue;
                }

                $operations[$route->form->path][$verb] = $route;
            }
        }

        return $operations;
    }

    /**
     * List the keys the audience's operation descriptions may be filed under.
     *
     * Each exported verb's own id, and its route's primary id, which the verb
     * falls back to.
     *
     * @param  string  $audience
     * @return list<string>
     */
    public function descriptionKeys(string $audience): array
    {
        $keys = [];

        foreach ($this->operations($audience) as $verbs) {
            foreach ($verbs as $verb => $route) {
                $keys[] = $route->operationIds[$verb] ?? null;
                $keys[] = $route->primaryOperationId;
            }
        }

        return array_values(array_unique(array_filter($keys, is_string(...))));
    }

    /**
     * List the distinct tags of the audience's exported operations.
     *
     * @param  string  $audience
     * @return list<string>
     */
    public function tags(string $audience): array
    {
        $tags = [];

        foreach ($this->operations($audience) as $verbs) {
            foreach ($verbs as $route) {
                $tags[] = $route->tag;
            }
        }

        return array_values(array_unique($tags));
    }
}
