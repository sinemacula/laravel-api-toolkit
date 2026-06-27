<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;

/**
 * Base class for every toolkit resource collection.
 *
 * Common ancestor of the schema-driven {@see ApiResourceCollection} (entity
 * reads) and {@see ReportCollection} (aggregated read models). It contributes
 * the shared response envelope - pagination `meta`/`links` and the
 * `Total-Count` header - via {@see ProvidesApiEnvelope}, leaving item-level
 * transformation to each subclass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ToolkitCollection extends AnonymousResourceCollection
{
    use ProvidesApiEnvelope;
}
