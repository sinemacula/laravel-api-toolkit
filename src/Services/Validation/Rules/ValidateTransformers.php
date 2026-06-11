<?php

namespace SineMacula\ApiToolkit\Services\Validation\Rules;

use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;

/**
 * Validate that all transformer entries in the compiled schema are callable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateTransformers extends ValidatesCallableLists
{
    /**
     * Return the callable list to validate for the given field definition.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition  $field
     * @return array<int, callable(mixed, mixed): mixed>
     */
    #[\Override]
    protected function getCallables(CompiledFieldDefinition $field): array
    {
        return $field->transformers;
    }

    /**
     * Return the human-readable label used in defect messages.
     *
     * @return string
     */
    #[\Override]
    protected function getLabel(): string
    {
        return 'Transformer';
    }
}
