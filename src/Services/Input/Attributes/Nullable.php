<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input\Attributes;

use SineMacula\ApiToolkit\Services\Input\Attributes\Contracts\ValidationAttribute;

/**
 * Marks a constructor-promoted parameter as nullable.
 *
 * Contributes the `nullable` Laravel validation rule fragment when collected
 * by the RuleCompiler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Nullable implements ValidationAttribute
{
    /**
     * Return the Laravel rule fragments for nullable fields.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function toRules(): array
    {
        return ['nullable'];
    }
}
