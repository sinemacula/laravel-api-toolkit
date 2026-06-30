<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Provides automatic resolution of API resource classes for Eloquent models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ResolvesResource
{
    /** @var string|null */
    private ?string $customResourceClass = null;

    /**
     * Flush all memo-cached resource mappings.
     *
     * @return void
     */
    public static function flushResourceCache(): void
    {
        Cache::memo()->flush(); // @phpstan-ignore method.notFound
    }

    /**
     * Set a custom resource class to be used.
     *
     * @param  string|null  $resourceClass
     * @return $this
     */
    public function usingResource(?string $resourceClass): static
    {
        $this->customResourceClass = $resourceClass;

        return $this;
    }

    /**
     * Get the metadata cache writer used to persist resolved resource mappings.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    abstract protected function metadataCacheWriter(): MetadataCacheWriter;

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

        return $this->metadataCacheWriter()->rememberMetadataForever(CacheKeys::MODEL_RESOURCES->resolveKey([$class]), function () use ($class): ?string {

            $resource = Config::get('api-toolkit.resources.resource_map.' . $class);

            if ($resource && class_exists($resource) && in_array(ApiResourceInterface::class, class_implements($resource) ?: [], true)) {
                return $resource;
            }

            return null;
        });
    }
}
