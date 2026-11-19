<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Renders the declared columns of the pivot a resource was reached through.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RendersPivotColumns
{
    /** @var array{string, array<int, string>}|null The pivot accessor and the columns rendered from it */
    private ?array $pivot = null;

    /**
     * Render the given columns of the pivot loaded under the accessor.
     *
     * Mutates this instance, as withFields() does.
     *
     * @param  string  $accessor
     * @param  array<int, string>  $columns
     * @return static
     */
    public function withPivot(string $accessor, array $columns): static
    {
        $this->pivot = [$accessor, $columns];

        return $this;
    }

    /**
     * Resolve the declared columns the loaded pivot carries under the pivot
     * key, or nothing when it carries none.
     *
     * Each column is read through the pivot's casts; an absent one is omitted.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pivotPayload(): array
    {
        if ($this->pivot === null) {
            return [];
        }

        [$accessor, $columns] = $this->pivot;

        $pivot = $this->resource instanceof Model && $this->resource->relationLoaded($accessor) ? $this->resource->getRelation($accessor) : null;

        if (!$pivot instanceof Model) {
            return [];
        }

        $payload = [];

        foreach (array_intersect($columns, array_keys($pivot->getAttributes())) as $column) {
            $value = $pivot->getAttribute($column);

            $payload[$column] = $value instanceof \UnitEnum && !$value instanceof \BackedEnum ? PureEnumName::of($value) : $value;
        }

        return $payload === [] ? [] : ['pivot' => $payload];
    }
}
