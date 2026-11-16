<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use SineMacula\ApiToolkit\Schema\CompiledSchema;

/**
 * Schema validation rule contract.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface SchemaValidationRule
{
    /**
     * Validate the compiled schema for the given resource class.
     *
     * A rule reading the catalogue returns an unverified declaration wherever
     * the catalogue could not be read, never silence.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array;
}
