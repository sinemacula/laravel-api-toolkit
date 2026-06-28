<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input\Attributes;

use SineMacula\ApiToolkit\Services\Input\Attributes\Contracts\ValidationAttribute;

/**
 * Applies an inclusive maximum constraint to a constructor-promoted parameter.
 *
 * Contributes the `max:n` Laravel validation rule fragment when collected
 * by the RuleCompiler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Max implements ValidationAttribute
{
    /**
     * Create a new Max attribute instance.
     *
     * @param  int  $value
     */
    public function __construct(

        /** The inclusive maximum applied to the property's value. */
        private readonly int $value,
    ) {}

    /**
     * Return the Laravel rule fragments for the maximum constraint.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function toRules(): array
    {
        return ['max:' . $this->value];
    }
}
