<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

/**
 * Resource collection for aggregated read models (reports).
 *
 * Maps each item through its {@see ReportResource} (the `$collects` class) and
 * inherits the toolkit's response envelope - pagination `meta`/`links` and the
 * `Total-Count` header - from {@see ToolkitCollection}. A paginated report
 * therefore returns an envelope consistent with entity collections, despite
 * being backed by data-transfer objects rather than Eloquent models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ReportCollection extends ToolkitCollection {}
