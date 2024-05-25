<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
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
abstract class ApiResource extends JsonResource implements ApiResourceInterface
{
    /** @var bool Indicates whether to return all fields in the response */
    protected bool $all = false;

    /** @var array Explicit list of fields to be returned in the response */
    protected array $fields;

    /** @var array Fixed fields to include in the response */
    protected array $fixed = [];

    /** @var array Default fields to include in the response if no specific fields are requested */
    protected array $default = [];

    /**
     * Resolve the resource to an array.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return array
     */
    public function resolve($request = null): array
    {
        $data = parent::resolve($request);

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
     * Overrides the default fields and any requested fields with a provided
     * set.
     *
     * @param  array  $fields
     * @return static
     */
    public function withFields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Forces the response to include all available fields.
     *
     * @return static
     */
    public function withAll(): static
    {
        $this->all = true;

        return $this;
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
        return $this->fields ??= array_merge($this->resolveFields(), $this->getFixedFields());
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
