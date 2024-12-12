<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The base resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class BaseResource extends JsonResource
{
    /** @var bool Indicates whether to return all fields in the response */
    protected bool $all = false;

    /** @var array|null Explicit list of fields to be returned in the response */
    protected ?array $fields;

    /**
     * Overrides the default fields and any requested fields with a provided
     * set.
     *
     * @param  array|null  $fields
     * @return static
     */
    public function withFields(?array $fields = null): static
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
}
