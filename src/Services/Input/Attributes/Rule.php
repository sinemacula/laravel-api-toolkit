<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input\Attributes;

use SineMacula\ApiToolkit\Services\Input\Attributes\Contracts\ValidationAttribute;

/**
 * Passes raw Laravel rule fragments through without transformation.
 *
 * Acts as an escape hatch for rule fragments that the typed validation
 * attributes cannot express. All supplied string fragments are collected
 * and returned unchanged by the RuleCompiler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Rule implements ValidationAttribute
{
    /** @var array<int, string> */
    private readonly array $rules;

    /**
     * Create a new Rule attribute instance.
     *
     * @param  string  ...$rules
     */
    public function __construct(string ...$rules)
    {
        $this->rules = array_values($rules);
    }

    /**
     * Return the raw rule fragments supplied to this attribute.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function toRules(): array
    {
        return $this->rules;
    }
}
