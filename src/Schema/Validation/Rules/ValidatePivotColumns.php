<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;

/**
 * Validate that every pivot() declaration renders columns its many-to-many
 * relation's pivot table carries onto a child resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidatePivotColumns implements SchemaValidationRule
{
    /** @var string The key each child renders its pivot columns under */
    private const string PIVOT_KEY = 'pivot';

    /**
     * Create a new pivot column validation rule.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @return void
     */
    public function __construct(

        /** Resolves the relation and reads its pivot table's columns */
        private SchemaIntrospectionProvider $introspector,
    ) {}

    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $findings = [];

        foreach ($schema->getFields() as $key => $field) {

            if ($field->pivot === []) {
                continue;
            }

            array_push($findings, ...$this->check($resourceClass, $key, $field, $modelClass));
        }

        return $findings;
    }

    /**
     * Check one field declaring pivot columns.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string|null  $modelClass
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    private function check(string $resourceClass, string $key, CompiledFieldDefinition $field, ?string $modelClass): array
    {
        $defect = $this->describeChildDefect($field);

        if ($defect !== null) {
            return [new SchemaValidationError(resourceClass: $resourceClass, fieldKey: $key, defect: $defect)];
        }

        return $modelClass !== null && is_subclass_of($modelClass, Model::class)
            ? $this->checkRelation($resourceClass, $key, $field, new $modelClass)
            : [];
    }

    /**
     * Describe why the field cannot render pivot columns on its children, or
     * null when it can.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return string|null
     */
    private function describeChildDefect(CompiledFieldDefinition $field): ?string
    {
        $child = (string) $field->resource;

        if ($field->relation === null || $field->accessor !== null || !is_subclass_of($child, ApiResource::class)) {
            return 'Field declares pivot columns but renders no child resource';
        }

        return SchemaCompiler::compile($child)->getField(self::PIVOT_KEY) === null ? null : sprintf(
            'Field declares pivot columns, and child resource %s declares a "%s" field they would overwrite',
            $child,
            self::PIVOT_KEY,
        );
    }

    /**
     * Check that the field's relation is many-to-many and its pivot table
     * carries the columns.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    private function checkRelation(string $resourceClass, string $key, CompiledFieldDefinition $field, Model $model): array
    {
        $relation = $this->introspector->resolveRelation((string) $field->relation, $model);

        if ($relation === null) {
            return [];
        }

        if (!$relation instanceof BelongsToMany) {
            return [new SchemaValidationError(resourceClass: $resourceClass, fieldKey: $key, defect: sprintf(
                'Relation "%s" declares pivot columns but resolves to %s; pivot() needs a BelongsToMany or MorphToMany',
                $field->relation,
                class_basename($relation),
            ))];
        }

        return $this->checkColumns($resourceClass, $key, $field->pivot, $relation->newPivot(), $model);
    }

    /**
     * Check that the pivot table carries each column.
     *
     * An empty listing means the pivot table's columns are unknown.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  array<int, string>  $columns
     * @param  \Illuminate\Database\Eloquent\Model  $pivot
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    private function checkColumns(string $resourceClass, string $key, array $columns, Model $pivot, Model $model): array
    {
        $listed = $this->introspector->getColumns($pivot);

        if ($listed === []) {
            return array_map(static fn (string $column): UnverifiedDeclaration => new UnverifiedDeclaration(
                resourceClass: $resourceClass,
                fieldKey: $key,
                check: sprintf('pivot table "%s" carries the "%s" column the pivot() declaration names', $pivot->getTable(), $column),
                modelClass: $model::class,
            ), $columns);
        }

        return array_map(static fn (string $column): SchemaValidationError => new SchemaValidationError(
            resourceClass: $resourceClass,
            fieldKey: $key,
            defect: sprintf('Field declares pivot column "%s", and pivot table "%s" carries no such column', $column, $pivot->getTable()),
        ), array_diff($columns, $listed));
    }
}
