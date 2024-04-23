<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use LogicException;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;

/**
 * The base API model.
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

    /** @var array Default fields to include in the response if no specific fields are requested */
    protected array $default = [];

    /**
     * Retrieves a value for a given key, filtering out the field if it's not
     * included in the allowed list. We use MissingValue to exclude fields not
     * present in the dynamic list from the serialized output.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key): mixed
    {
        if (!$this->hasField($key)) {
            return new MissingValue;
        }

        return parent::__get($key);
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

        return static::RESOURCE_TYPE;
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
     * Checks if a given field should be included in the response based on the
     * dynamically requested fields.
     *
     * @param  string  $key
     * @return bool
     */
    private function hasField(string $key): bool
    {
        return $this->shouldRespondWithAll() || in_array($key, $this->getFields());
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
        return $this->fields ??= $this->resolveFields();
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

}
