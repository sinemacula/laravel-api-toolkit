<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\Attributes\Max;
use SineMacula\ApiToolkit\Services\Input\Attributes\Min;
use SineMacula\ApiToolkit\Services\Input\Attributes\Required;
use SineMacula\ApiToolkit\Services\Input\Attributes\Rule;

/**
 * ServiceInput fixture with multiple ValidationAttribute annotations.
 *
 * Used by RuleCompilerTest to exercise attribute-fragment layering.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AttributedInput implements ServiceInput
{
    /**
     * Create a new AttributedInput fixture instance.
     *
     * @param  string  $email
     * @param  int  $count
     */
    public function __construct(

        /** Email field with required, min, max and a raw rule fragment. */
        #[Max(100)]
        #[Min(1)]
        #[Required]
        #[Rule('email')]
        public readonly string $email = '',

        /** Plain integer with no attribute annotations. */
        public readonly int $count = 0,
    ) {}

    /**
     * Return the input as an associative array.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [];
    }
}
