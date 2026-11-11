<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

/**
 * Base class for aggregated read-model (report) resources.
 *
 * Reports have no intrinsic entity type, so `_type` is opt-in via withType().
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
