<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;

/**
 * Polymorphic resource class for handling dynamic resource resolution in a
 * polymorphic relationship context.
 *
 * This class maps model instances to their corresponding API resource classes
 * based on a configurable mapping. It is designed to handle responses where
 * different types of models need to be returned in a uniform API structure.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class PolymorphicResource extends JsonResource
{
    /** @var bool Whether to return all fields in the response */
    protected bool $all = false;

    /** @var array<int, string>|null Explicit list of fields to be returned in the response */
    protected ?array $fields = null;

    /** @var array<int, string>|null Explicit list of fields to be excluded from the response */
    protected ?array $excludedFields = null;

    /**
     * Force the response to include all available fields.
     *
     * @return static
     */
    public function withAll(): static
    {
        $this->all = true;

        return $this;
    }

    /**
     * Override the default fields and any requested fields.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    public function withFields(?array $fields = null): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Exclude specific fields from the response.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    public function withoutFields(?array $fields = null): static
    {
        $this->excludedFields = $fields;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>|null
     */
    public function toArray(Request $request): ?array
    {
        if (!$this->resource) {
            return null;
        }

        $resource = $this->mapResource($this->resource);

        if ($this->all) {
            $resource->withAll();
        }

        return [
            '_type' => $resource::getResourceType(),
            ...$resource->resolve($request),
        ];
    }

    /**
     * Map the given resource to its corresponding resource class based on a
     * configuration map.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
     *
     * @throws \LogicException
     */
    private function mapResource(mixed $resource): ApiResourceInterface
    {
        $map   = Config::get('api-toolkit.resources.resource_map', []);
        $class = $resource::class;

        if (isset($map[$class])) {
            return new $map[$class]($resource, false, $this->fields ?? null);
        }

        throw new \LogicException("Resource not found for: {$class}");
    }
}
