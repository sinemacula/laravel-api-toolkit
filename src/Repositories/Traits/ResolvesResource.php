<?php

namespace SineMacula\ApiToolkit\Repositories\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Provides automatic resolution of API resource classes for Eloquent models.
 *
 * @author      Michael Stivala <michael.stivala@verifast.com>
 * @copyright   2025 Verifast, Inc.
 */
trait ResolvesResource
{
    /** @var string|null */
    private ?string $customResourceClass = null;

    /**
     * Set a custom resource class to be used.
     *
     * @param  string|null  $resource_class
     * @return $this
     */
    public function usingResource(?string $resource_class): static
    {
        $this->customResourceClass = $resource_class;

        return $this;
    }

    /**
     * Resolve the resource class for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    protected function resolveResource(Model $model): ?string
    {
        return $this->customResourceClass ?? $this->getResourceFromModel($model);
    }

    /**
     * Get the resource from the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    protected function getResourceFromModel(Model $model): ?string
    {
        $class = $model::class;

        return Cache::memo()->rememberForever(CacheKeys::MODEL_RESOURCES->resolveKey([$class]), function () use ($class) {

            $resource = Config::get('api-toolkit.resources.resource_map.' . $class);

            if ($resource && class_exists($resource) && in_array(ApiResourceInterface::class, class_implements($resource) ?: [], true)) {
                return $resource;
            }
        });
    }
}
