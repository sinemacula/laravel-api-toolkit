<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input\Attributes;

use SineMacula\ApiToolkit\Services\Input\Attributes\Contracts\ValidationAttribute;

/**
 * Applies an inclusive minimum constraint to a constructor-promoted parameter.
 *
 * Contributes the `min:n` Laravel validation rule fragment when collected
 * by the RuleCompiler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Min implements ValidationAttribute
{
    /**
     * Create a new Min attribute instance.
     *
     * @param  int  $value
     */
    public function __construct(

        /** The inclusive minimum applied to the property's value. */
        private readonly int $value,
    ) {}

    /**
     * Return the Laravel rule fragments for the minimum constraint.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function toRules(): array
    {
        return ['min:' . $this->value];
    }
}
