<?php

namespace SineMacula\ApiToolkit\Services;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;

/**
 * Orchestrates schema validation across all registered resources.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaValidator
{
    /** @var array<int|string, \SineMacula\ApiToolkit\Contracts\SchemaValidationRule> */
    private readonly array $rules;

    /**
     * Create a new schema validator.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaValidationRule  ...$rules
     */
    public function __construct(SchemaValidationRule ...$rules)
    {
        $this->rules = $rules;
    }

    /**
     * Validate all schemas in the provided resource map.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function validate(array $resourceMap): void
    {
        /** @var array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError> $errors */
        $errors = [];

        foreach ($resourceMap as $modelClass => $resourceClass) {

            $schema = SchemaCompiler::compile($resourceClass);

            foreach ($this->rules as $rule) {
                array_push($errors, ...$rule->validate($resourceClass, $modelClass, $schema));
            }
        }

        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }
    }
}
