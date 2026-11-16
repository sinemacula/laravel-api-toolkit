<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fixture plain JSON resource that is not an API resource.
 *
 * It declares no resource type, so it pins no `_type` and a union naming it has
 * no type discriminator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PlainJsonResource extends JsonResource {}
