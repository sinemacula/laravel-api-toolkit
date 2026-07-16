<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Concerns;

/**
 * Outer recording concern declared first in the concern list.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OuterRecordingConcern extends RecordingConcern
{
    /**
     * Return the label for this concern.
     *
     * @return string
     */
    #[\Override]
    protected function label(): string
    {
        return 'outer';
    }
}
