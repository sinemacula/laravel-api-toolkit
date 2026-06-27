<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base class for every toolkit single-item resource.
 *
 * Common ancestor of the schema-driven {@see ApiResource} (entity reads) and
 * {@see ReportResource} (aggregated read models), so the toolkit's response
 * conventions are anchored in one place. The single-item envelope (the `data`
 * wrap) is inherited from Laravel's JsonResource; this base owns the shared
 * `_type` discriminator convention via {@see self::withType()}.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ToolkitResource extends JsonResource
{
    /**
     * Prepend the `_type` discriminator to a resolved payload.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withType(string $type, array $data): array
    {
        return ['_type' => $type, ...$data];
    }
}
