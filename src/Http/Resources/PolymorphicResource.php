<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use LogicException;
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
 * @copyright   2025 Sine Macula Limited.
 */
class PolymorphicResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|null
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
            ...$resource->resolve($request)
        ];
    }

    /**
     * Map the given resource to its corresponding resource class based on a
     * configuration map.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
     *
     * @throws LogicException
     */
    private function mapResource(mixed $resource): ApiResourceInterface
    {
        $map   = Config::get('api-toolkit.resources.resource_map', []);
        $class = $resource::class;

        if (isset($map[$class])) {
            return new $map[$class]($resource, false, $this->fields ?? null);
        }

        throw new LogicException("Resource not found for: {$class}");
    }
}
