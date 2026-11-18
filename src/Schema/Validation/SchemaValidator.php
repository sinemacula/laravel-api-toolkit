<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
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
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaValidationRule  ...$rules
     */
    public function __construct(

        /** Resolves the relations a filter may descend through */
        private SchemaIntrospectionProvider $introspector,

        SchemaValidationRule ...$rules,
    ) {
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
     * Run every rule over the resource map and every resource a filter reaches
     * through it, keeping what could not be verified apart from the defects.
     *
     * Each resource is validated once per model it serves.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function report(array $resourceMap): SchemaValidationReport
    {
        $traversal = new RelationTraversal($this->introspector, $resourceMap);
        $queue     = [];
        $seen      = [];
        $findings  = [];

        foreach ($resourceMap as $modelClass => $resourceClass) {
            $queue[] = [$resourceClass, $modelClass];
            $seen[]  = $resourceClass . '|' . $modelClass;
        }

        while ($queue !== []) {

            [$resourceClass, $modelClass] = array_shift($queue);

            $schema = SchemaCompiler::compile($resourceClass);

            foreach ($this->rules as $rule) {
                array_push($findings, ...$rule->validate($resourceClass, $modelClass, $schema));
            }

            foreach ($traversal->edges($resourceClass, $modelClass) as $edge) {

                $pair = $edge->resource . '|' . $edge->model;

                if ($edge->resource === null || in_array($pair, $seen, true)) {
                    continue;
                }

                $queue[] = [$edge->resource, $edge->model];
                $seen[]  = $pair;
            }
        }

        return new SchemaValidationReport(
            errors: array_values(array_filter($findings, static fn (object $finding): bool => $finding instanceof SchemaValidationError)),
            unverified: array_values(array_filter($findings, static fn (object $finding): bool => $finding instanceof UnverifiedDeclaration)),
            resourceCount: count($seen),
        );
    }
}
