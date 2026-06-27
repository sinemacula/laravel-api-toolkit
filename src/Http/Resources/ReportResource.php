<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

/**
 * Base class for aggregated read-model (report) resources.
 *
 * Reports are a second read model: a raw aggregation query is hydrated into a
 * plain data-transfer object and transformed here, sharing the toolkit's
 * response envelope without the schema-driven, Eloquent-bound machinery of
 * {@see ApiResource}. Subclasses implement `toArray()` to shape their payload
 * and may stamp a discriminator via {@see ToolkitResource::withType()}; reports
 * carry a `_type` only when they opt into one, since an aggregated row has no
 * intrinsic entity type.
 *
 * Overriding {@see self::newCollection()} ensures `static::collection(...)`
 * yields a {@see ReportCollection}, so paginated reports inherit the identical
 * envelope as entity collections.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ReportResource extends ToolkitResource
{
    /**
     * Create a new resource collection instance.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ReportCollection
     */
    #[\Override]
    protected static function newCollection(#[\SensitiveParameter] mixed $resource): ReportCollection
    {
        return new ReportCollection($resource, static::class);
    }
}
