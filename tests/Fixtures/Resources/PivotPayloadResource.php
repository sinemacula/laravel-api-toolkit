<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RendersPivotColumns;

/**
 * Fixture plain JSON resource that renders pivot columns and exposes their
 * payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PivotPayloadResource extends JsonResource
{
    use RendersPivotColumns;

    /**
     * Expose the pivot payload.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payload(): array
    {
        return $this->pivotPayload();
    }
}
