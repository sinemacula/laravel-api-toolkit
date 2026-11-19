<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that every relation limit bounds a to-many relation it can render.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateRelationLimits implements SchemaValidationRule
{
    /** @var list<class-string> Relation classes that load at most one record */
    private const array TO_ONE = [BelongsTo::class, HasOne::class, HasOneThrough::class, MorphOne::class];

    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $errors = in_array(RelationLimit::TRUNCATED_KEY, $schema->getFieldKeys(), true)
            ? [$this->error($resourceClass, RelationLimit::TRUNCATED_KEY, sprintf('The key "%s" is reserved for the relations a response truncates', RelationLimit::TRUNCATED_KEY))]
            : [];

        /** @var array<string, int> $limits */
        $limits = [];

        foreach ($schema->getFields() as $key => $field) {

            $defect = $field->relation === null ? null : $this->describeFieldDefect($field, $field->relation, $modelClass, $limits);

            if ($defect === null) {
                continue;
            }

            $errors[] = $this->error($resourceClass, $key, $defect);
        }

        return $errors;
    }

    /**
     * Describe why the field's relation limit is invalid, or null if it is not.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $relation
     * @param  string|null  $modelClass
     * @param  array<string, int>  $limits
     * @return string|null
     */
    private function describeFieldDefect(CompiledFieldDefinition $field, string $relation, ?string $modelClass, array &$limits): ?string
    {
        $toOne  = $this->isToOne($modelClass, $relation);
        $defect = $this->describeLimitDefect($field, $relation, $toOne);

        if ($defect !== null || $toOne) {
            return $defect;
        }

        return $this->describeLimitConflict($field, $relation, $limits);
    }

    /**
     * Describe why the field's declared limit cannot apply, or null if it can.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $relation
     * @param  bool  $toOne
     * @return string|null
     */
    private function describeLimitDefect(CompiledFieldDefinition $field, string $relation, bool $toOne): ?string
    {
        if ($field->limit === null) {
            return null;
        }

        if ($field->resource === null || $field->accessor !== null) {
            return sprintf('Relation "%s" declares a limit but renders no child resource', $relation);
        }

        return $toOne
            ? sprintf('Relation "%s" declares a limit but its method returns a to-one relation', $relation)
            : null;
    }

    /**
     * Describe a limit that differs from another field's over one relation.
     *
     * The planner loads each relation path once, so one window must serve all.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $relation
     * @param  array<string, int>  $limits
     * @return string|null
     */
    private function describeLimitConflict(CompiledFieldDefinition $field, string $relation, array &$limits): ?string
    {
        $limit = RelationLimit::for($field);
        $first = $limits[$relation] ??= $limit;

        return $first === $limit
            ? null
            : sprintf('Relation "%s" is loaded once, so every field over it must share one limit, not %d and %d', $relation, $first, $limit);
    }

    /**
     * Determine whether the model's relation method declares a to-one return.
     *
     * A union qualifies only when every member is a to-one relation.
     *
     * @param  string|null  $modelClass
     * @param  string  $relation
     * @return bool
     */
    private function isToOne(?string $modelClass, string $relation): bool
    {
        if ($modelClass === null || !method_exists($modelClass, $relation)) {
            return false;
        }

        $returnType = (new \ReflectionMethod($modelClass, $relation))->getReturnType();
        $members    = $returnType instanceof \ReflectionUnionType ? $returnType->getTypes() : [$returnType];

        foreach ($members as $member) {
            if (!$member instanceof \ReflectionNamedType || !$this->isToOneClass($member->getName())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the named class is a relation that loads one record.
     *
     * @param  string  $name
     * @return bool
     */
    private function isToOneClass(string $name): bool
    {
        return array_filter(self::TO_ONE, static fn (string $toOne): bool => is_a($name, $toOne, true)) !== [];
    }

    /**
     * Build a validation error for the given field.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  string  $defect
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError
     */
    private function error(string $resourceClass, string $key, string $defect): SchemaValidationError
    {
        return new SchemaValidationError(resourceClass: $resourceClass, fieldKey: $key, defect: $defect);
    }
}
