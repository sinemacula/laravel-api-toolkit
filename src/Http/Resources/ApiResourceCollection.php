<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Enumerable;

/**
 * Resource collection that applies the requested field selection to each item.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ApiResourceCollection extends ToolkitCollection
{
    /** @var array<int, string>|null Explicit list of fields to be returned in the collection */
    protected ?array $fields;

    /** @var array<int, string>|null Explicit list of fields to be excluded in the response */
    protected ?array $excludedFields;

    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<int|string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $resourceClass */
        $resourceClass = $this->collects;

        return collect($this->collection)->map(function ($item) use ($resourceClass, $request): array {

            if ($item instanceof ApiResource) {

                if (isset($this->fields)) {
                    $item->withFields($this->fields);
                }

                if (isset($this->excludedFields)) {
                    $item->withoutFields($this->excludedFields);
                }

                return $item->resolve($request);
            }

            return (new $resourceClass($item, false, $this->fields ?? null, $this->excludedFields ?? null))->resolve($request);
        })->all();
    }

    /**
     * Override the default and requested fields with the given set.
     *
     * @param  array<int, string>|null  $fields
     * @return self
     */
    public function withFields(?array $fields = null): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Remove the given fields from the response.
     *
     * @param  array<int, string>|null  $fields
     * @return self
     */
    public function withoutFields(?array $fields = null): self
    {
        $this->excludedFields = $fields;

        return $this;
    }

    /**
     * Wrap each item in the collected resource by its value alone.
     *
     * The framework passes each item's key as the second constructor argument,
     * which a resource reads as its load-missing flag, so every item after the
     * first would load the relations its planner deliberately left unloaded.
     *
     * @param  mixed  $resource
     * @return mixed
     */
    #[\Override]
    protected function collectResource(mixed $resource): mixed
    {
        $collects = $this->collects;
        $wrap     = static fn (mixed $item): mixed => $item instanceof $collects ? $item : new $collects($item);

        return parent::collectResource(match (true) {
            $resource instanceof AbstractPaginator, $resource instanceof AbstractCursorPaginator => $resource->through($wrap),
            $resource instanceof Enumerable                                                      => $resource->map($wrap),
            is_array($resource)                                                                  => array_map($wrap, $resource),
            default                                                                              => $resource,
        });
    }
}
