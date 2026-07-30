<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Schema;

use SineMacula\ApiToolkit\Schema\Concerns\HasMetricModifiers;

/**
 * Minimal concrete consumer of the HasMetricModifiers trait for isolated
 * testing of the shared metric modifier surface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetricModifierStub
{
    use HasMetricModifiers;
}
