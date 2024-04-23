<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
 * @copyright   2024 Sine Macula Limited.
 */
class PolymorphicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = $this->mapResource($this->resource);

        return [
            '_type' => $resource::getResourceType(),
            ...$resource->toArray($request)
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
        $map   = Config::get('api-toolkit.resources.morph_map', []);
        $class = get_class($resource);

        if (isset($map[$class])) {
            return new $map[$class]($resource);
        }

        throw new LogicException("Resource not found for: {$class}");
    }
}
