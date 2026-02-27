<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
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
class PolymorphicResource extends BaseResource
{
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
            // @phpstan-ignore method.notFound (withAll() is provided by BaseResource)
            $resource->withAll();
        }

        /** @phpstan-ignore method.notFound (resolve() is provided by JsonResource) */
        return [
            '_type' => $resource::getResourceType(),
            // @phpstan-ignore method.notFound (resolve() is provided by JsonResource)
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
        $map = Config::get('api-toolkit.resources.resource_map', []);
        /** @phpstan-ignore classConstant.nonObject (resource is always an object at this point) */
        $class = $resource::class;

        if (isset($map[$class])) {
            /** @phpstan-ignore return.type (resolved class implements ApiResourceInterface) */
            return new $map[$class]($resource, false, $this->fields ?? null);
        }

        throw new \LogicException("Resource not found for: {$class}");
    }
}
