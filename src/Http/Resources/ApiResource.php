<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Support\Facades\Config;
use LogicException;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;

/**
 * The base API resource.
 *
 * This handles dynamic field filtering based on API query parameters. It
 * leverages a global query parser to determine which fields should be included
 * in the response.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class ApiResource extends BaseResource implements ApiResourceInterface
{
    /** @var array Default fields to include in the response if no specific fields are requested */
    protected array $default = [];

    /** @var array Fixed fields to include in the response */
    protected array $fixed = [];

    /**
     * Resolve the resource to an array.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return array
     */
    public function resolve($request = null): array
    {
        $data = [
            '_type' => $this->getResourceType(),
            ...parent::resolve($request)
        ];

        return $this->shouldRespondWithAll()
            ? $data
            : array_intersect_key($data, array_flip($this->getFields()));
    }

    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string
    {
        if (!defined(static::class . '::RESOURCE_TYPE')) {
            throw new LogicException('The RESOURCE_TYPE constant must be defined on the resource');
        }

        return strtolower(static::RESOURCE_TYPE);
    }

    /**
     * Create a new resource collection instance.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    protected static function newCollection($resource): ApiResourceCollection
    {
        return new ApiResourceCollection($resource, static::class);
    }

    /**
     * Determines whether all fields should be included in the response.
     *
     * @return bool
     */
    private function shouldRespondWithAll(): bool
    {
        return $this->all || in_array(':all', ApiQuery::getFields(self::getResourceType()) ?? []);
    }

    /**
     * Gets the fields that should be included in the response.
     *
     * @return array
     */
    private function getFields(): array
    {
        $this->fields ??= $this->resolveFields();

        return array_merge($this->fields, $this->getFixedFields());
    }

    /**
     * Resolves and returns the fields based on the API query or defaults if no
     * specific fields are requested.
     *
     * @return array
     */
    private function resolveFields(): array
    {
        return ApiQuery::getFields(self::getResourceType()) ?? $this->default;
    }

    /**
     * Gets the fields that should always be included in the response.
     *
     * @return array
     */
    private function getFixedFields(): array
    {
        return array_merge(Config::get('api-toolkit.resources.fixed_fields'), $this->fixed);
    }
}
