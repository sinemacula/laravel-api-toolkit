<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
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
     * Validate all schemas in the provided resource map and every catalogued
     * resource it does not bind.
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
     * Run every rule over the resource map and every catalogued resource it
     * does not bind, keeping what could not be verified apart from the defects.
     *
     * An unbound resource is validated with no model, so the rules reading the
     * model's table skip it.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function report(array $resourceMap): SchemaValidationReport
    {
        $unbound  = array_diff(ResourceTypeIndex::all(), $resourceMap);
        $findings = [];

        foreach ($resourceMap as $modelClass => $resourceClass) {
            array_push($findings, ...$this->check($resourceClass, $modelClass));
        }

        foreach ($unbound as $resourceClass) {
            array_push($findings, ...$this->check($resourceClass, null));
        }

        return new SchemaValidationReport(
            errors: array_values(array_filter($findings, static fn (object $finding): bool => $finding instanceof SchemaValidationError)),
            unverified: array_values(array_filter($findings, static fn (object $finding): bool => $finding instanceof UnverifiedDeclaration)),
            resourceCount: count($resourceMap) + count($unbound),
        );
    }

    /**
     * Run every rule over one resource.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private function check(string $resourceClass, ?string $modelClass): array
    {
        $schema   = SchemaCompiler::compile($resourceClass);
        $findings = [];

        foreach ($this->rules as $rule) {
            array_push($findings, ...$rule->validate($resourceClass, $modelClass, $schema));
        }

        return $findings;
    }
}
