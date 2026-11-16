<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that each relation aggregate names a column the database can
 * aggregate and an attribute the owner model leaves untouched.
 *
 * An empty column listing means the columns are unknown, so it proves nothing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidateAggregateColumns implements SchemaValidationRule
{
    /** @var array<int, string> Column types a sum or an average cannot add up */
    private const array NON_NUMERIC_TYPES = [
        'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string', 'enum', 'set', 'citext', 'bpchar',
        'date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz', 'time', 'timetz', 'year', 'interval',
        ...self::BOOLEAN_TYPES, 'json', 'jsonb', 'uuid',
        'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea',
    ];

    /** @var array<int, string> Column types a minimum or a maximum cannot order */
    private const array UNORDERED_TYPES = [
        ...self::BOOLEAN_TYPES, 'json', 'jsonb',
        'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea',
    ];

    /** @var array<int, string> Built-in casts that read a minimum or a maximum faithfully */
    private const array ORDERED_CASTS = [
        'int', 'integer', 'real', 'float', 'double', 'decimal', 'string', 'bool', 'boolean',
        'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp',
    ];

    /** @var array<int, string> Column types holding a boolean */
    private const array BOOLEAN_TYPES = ['boolean', 'bool'];

    /**
     * Create a new aggregate column validation rule.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @return void
     */
    public function __construct(

        /** Reads the relations and column listings behind the models */
        private SchemaIntrospectionProvider $introspector,
    ) {}

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
        if ($modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Model $owner */
        $owner  = new $modelClass;
        $errors = [];

        foreach ($schema->getAggregateDefinitions() as $key => $aggregate) {
            foreach ($this->defects($owner, $aggregate) as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: $defect,
                );
            }
        }

        return $errors;
    }

    /**
     * Return the defects a single aggregate declaration carries.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $owner
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $aggregate
     * @return array<int, string>
     */
    private function defects(Model $owner, CompiledAggregateDefinition $aggregate): array
    {
        return [...$this->attributeDefects($owner, $aggregate->attribute()), ...$this->relationDefects($owner, $aggregate)];
    }

    /**
     * Return the defects the aggregated relation and its column carry.
     *
     * An unresolvable relation is left to the relation-method rule.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $owner
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $aggregate
     * @return array<int, string>
     */
    private function relationDefects(Model $owner, CompiledAggregateDefinition $aggregate): array
    {
        $relation = $this->introspector->resolveRelation($aggregate->relation, $owner);

        if ($relation instanceof MorphTo) {
            return [sprintf('Aggregate relation "%s" is a MorphTo, whose related model is unknown until each row is read', $aggregate->relation)];
        }

        if ($relation === null || $aggregate->column === null) {
            return [];
        }

        return $this->columnDefects($relation->getRelated(), $aggregate->function, $aggregate->column);
    }

    /**
     * Return the defects the owner model raises against the loaded attribute.
     *
     * The load path copies the result through forceFill(), which runs setters,
     * so the query path and the load path would otherwise disagree.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $owner
     * @param  string  $attribute
     * @return array<int, string>
     */
    private function attributeDefects(Model $owner, string $attribute): array
    {
        $interference = match (true) {
            in_array($attribute, $this->introspector->getColumns($owner), true) => 'a column',
            $owner->hasCast($attribute)                                         => 'a cast',
            $owner->hasSetMutator($attribute),
            $owner->hasAttributeSetMutator($attribute) => 'a setter',
            default                                    => null,
        };

        if ($interference === null) {
            return [];
        }

        return [sprintf('Aggregate attribute "%s" is also %s on model "%s", so the aggregate cannot be read back unchanged', $attribute, $interference, $owner::class)];
    }

    /**
     * Return the defects the related column raises against the function.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $related
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string  $column
     * @return array<int, string>
     */
    private function columnDefects(Model $related, AggregateFunction $function, string $column): array
    {
        $columns = $this->introspector->getColumns($related);

        if ($columns !== [] && !in_array($column, $columns, true)) {
            return [sprintf('Aggregate column "%s" does not exist on table "%s"', $column, $related->getTable())];
        }

        $ordered = $function === AggregateFunction::MIN || $function === AggregateFunction::MAX;
        $defects = $this->typeDefects($related, $function, $column, $ordered);

        return $ordered ? [...$defects, ...$this->readDefects($related, $function, $column)] : $defects;
    }

    /**
     * Return the defect the column's type raises against the function.
     *
     * An unmapped driver type passes, since it proves nothing.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $related
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string  $column
     * @param  bool  $ordered
     * @return array<int, string>
     */
    private function typeDefects(Model $related, AggregateFunction $function, string $column, bool $ordered): array
    {
        $type = $this->introspector->getColumnDefinitions($related)[$column]->typeName ?? null;

        if ($type === null || !in_array($type, $ordered ? self::UNORDERED_TYPES : self::NON_NUMERIC_TYPES, true)) {
            return [];
        }

        $hint = $ordered && in_array($type, self::BOOLEAN_TYPES, true) ? ', so declare an Exists aggregate instead' : '';

        return [sprintf('The %s aggregate cannot be computed over column "%s" of type "%s"%s', $function->value, $column, $type, $hint)];
    }

    /**
     * Return the defects the related model raises against reading the value.
     *
     * A minimum or maximum is read through the related model's cast, so only a
     * cast that reads a single stored value faithfully is accepted.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $related
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string  $column
     * @return array<int, string>
     */
    private function readDefects(Model $related, AggregateFunction $function, string $column): array
    {
        $cast  = $related->getCasts()[$column] ?? null;
        $cause = match (true) {
            $related->hasGetMutator($column),
            $related->hasAttributeGetMutator($column)        => 'is read through an accessor',
            is_string($cast) && !$this->isOrderedCast($cast) => sprintf('is cast with "%s"', $cast),
            default                                          => null,
        };

        if ($cause === null) {
            return [];
        }

        return [sprintf(
            'The %s aggregate over column "%s" %s on model "%s", which may not read the aggregated value back; transform() the aggregate instead',
            $function->value,
            $column,
            $cause,
            $related::class,
        )];
    }

    /**
     * Determine whether a cast is a built-in scalar or date cast, or a backed
     * enum.
     *
     * @param  string  $cast
     * @return bool
     */
    private function isOrderedCast(string $cast): bool
    {
        if (enum_exists($cast)) {
            return is_subclass_of($cast, \BackedEnum::class);
        }

        $base = strtok(strtolower($cast), ':');

        return in_array($base, self::ORDERED_CASTS, true);
    }
}
