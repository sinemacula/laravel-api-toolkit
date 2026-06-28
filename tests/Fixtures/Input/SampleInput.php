<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use SineMacula\ApiToolkit\Services\Input\Attributes\Max;
use SineMacula\ApiToolkit\Services\Input\Attributes\Nullable;
use SineMacula\ApiToolkit\Services\Input\Attributes\Required;
use SineMacula\ApiToolkit\Services\Input\InputData;

/**
 * Concrete InputData fixture with attribute-validated promoted properties.
 *
 * Demonstrates the canonical pattern for typed, immutable service inputs: a
 * final class with readonly promoted properties annotated with validation
 * attributes and readable as typed properties by handlers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SampleInput extends InputData
{
    /**
     * Create a new SampleInput instance.
     *
     * @param  string  $city
     * @param  int|null  $age
     */
    public function __construct(

        /** The city name; required and validated as a string. */
        #[Required]
        public readonly string $city,

        /** The age; optional, nullable, with an inclusive maximum of 120. */
        #[ Max(120), Nullable]
        public readonly ?int $age = null,
    ) {}
}
