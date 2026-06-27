<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input\Attributes\Contracts;

/**
 * Attribute contract for contributing Laravel validation rule fragments.
 *
 * Implementors are declared on constructor-promoted parameters of input
 * objects and are collected by the RuleCompiler to build per-field rule
 * arrays.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ValidationAttribute
{
    /**
     * Return the Laravel rule fragments contributed by this attribute.
     *
     * @return array<int, string>
     */
    public function toRules(): array;
}
