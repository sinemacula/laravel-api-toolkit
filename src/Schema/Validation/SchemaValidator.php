<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Orchestrates schema validation across all registered resources.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaValidator
{
    /** @var array<int|string, \SineMacula\ApiToolkit\Contracts\SchemaValidationRule> */
    private array $rules;

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
     * Declarations the catalogue could not verify do not fail validation.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function validate(array $resourceMap): void
    {
        $report = $this->report($resourceMap);

        if ($report->errors !== []) {
            throw new InvalidSchemaException($report->errors);
        }
    }

    /**
     * Run every rule over the resource map, keeping what could not be verified
     * apart from the defects.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport
     */
    public function report(array $resourceMap): SchemaValidationReport
    {
        $findings = [];

        foreach ($resourceMap as $modelClass => $resourceClass) {

            $schema = SchemaCompiler::compile($resourceClass);

            foreach ($this->rules as $rule) {
                array_push($findings, ...$rule->validate($resourceClass, $modelClass, $schema));
            }
        }

        return new SchemaValidationReport(
            errors: array_values(array_filter($findings, static fn (object $finding): bool => $finding instanceof SchemaValidationError)),
            unverified: array_values(array_filter($findings, static fn (object $finding): bool => $finding instanceof UnverifiedDeclaration)),
            resourceCount: count($resourceMap),
        );
    }
}
