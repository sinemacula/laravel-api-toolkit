<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that an ordered index leads with every sortable column.
 *
 * The column must lead the index; mere membership cannot serve an ordered read.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidateIndexBacking implements SchemaValidationRule
{
    /** @var string The index kind that holds an order on every supported engine */
    private const string ORDERED_INDEX = 'btree';

    /**
     * Create a new index backing validation rule.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector  $eligibility
     * @return void
     */
    public function __construct(

        /** Reads the index catalogue behind the model's table */
        private SchemaIntrospectionProvider $introspector,

        /** Reads which of those indexes a proof may rest on */
        private IndexEligibilityInspector $eligibility = new IndexEligibilityInspector,
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
        $declared = $this->declaredFields($schema);

        if ($declared === []) {
            return [];
        }

        $model = $this->resolveModel($modelClass);

        // Without a model the catalogue is unknown
        $indexes = $model === null ? null : $this->introspector->getIndexes($model);
        $table   = $model?->getTable() ?? '';
        $errors  = [];

        $eligibility = $model !== null && $indexes !== null
            ? $this->eligibility->inspect($table, $model->getConnection())
            : new IndexEligibility;

        foreach ($declared as $key => $field) {

            foreach ($this->defects($field, $indexes, $table, $eligibility) as $defect) {
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
     * Resolve the model behind the resource, or null when it is not a model.
     *
     * @param  string|null  $modelClass
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    private function resolveModel(?string $modelClass): ?Model
    {
        if ($modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model */
        return new $modelClass;
    }

    /**
     * Return the fields declaring a sort or an index override, by key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>
     */
    private function declaredFields(CompiledSchema $schema): array
    {
        $declared = [];

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null || !$this->declaresAnything($field)) {
                continue;
            }

            $declared[$key] = $field;
        }

        return $declared;
    }

    /**
     * Determine whether the field carries anything this rule governs.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function declaresAnything(CompiledFieldDefinition $field): bool
    {
        return $field->sortable        !== null
            || $field->indexedBy       !== null
            || $field->unindexedReason !== null;
    }

    /**
     * Return the defects a single field carries.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null  $indexes
     * @param  string  $table
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility  $eligibility
     * @return array<int, string>
     */
    private function defects(CompiledFieldDefinition $field, ?array $indexes, string $table, IndexEligibility $eligibility): array
    {
        if ($field->indexedBy !== null && $field->unindexedReason !== null) {
            return ['Field declares both a backing index and an index exemption, so neither governs the sort'];
        }

        if ($field->sortable === null) {

            return $field->indexedBy !== null
                ? ['Field declares index backing but is not declared sortable, so the declaration governs nothing']
                : ['Field declares an index exemption but is not declared sortable, so the exemption governs nothing'];
        }

        // A null catalogue is unknown, so it proves nothing
        return $field->unindexedReason !== null || $indexes === null
            ? []
            : $this->backingDefects($field->sortable, $field->indexedBy, $indexes, $table, $eligibility);
    }

    /**
     * Describe what the sortable column is missing, or nothing when backed.
     *
     * @param  string  $column
     * @param  string|null  $declared
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @param  string  $table
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility  $eligibility
     * @return array<int, string>
     */
    private function backingDefects(string $column, ?string $declared, array $indexes, string $table, IndexEligibility $eligibility): array
    {
        if ($declared !== null) {
            return $this->namedDefects($declared, $column, $indexes, $table, $eligibility);
        }

        return $this->ledByAnOrderedIndex($column, $indexes, $eligibility) ? [] : [sprintf(
            'Field is declared sortable against "%s", and no ordered index on table "%s" leads with that column',
            $column,
            $table,
        )];
    }

    /**
     * Describe what the named index is missing, or nothing when it is usable.
     *
     * @param  string  $declared
     * @param  string  $column
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @param  string  $table
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility  $eligibility
     * @return array<int, string>
     */
    private function namedDefects(string $declared, string $column, array $indexes, string $table, IndexEligibility $eligibility): array
    {
        if (!$this->carries($declared, $indexes)) {

            return [sprintf(
                'Field declares the "%s" index behind sortable column "%s", and table "%s" carries no index of that name',
                $declared,
                $column,
                $table,
            )];
        }

        return $eligibility->disregards($declared) ? [sprintf(
            'Field declares the "%s" index behind sortable column "%s", and table "%s" carries it, but the connection will not plan against it',
            $declared,
            $column,
            $table,
        )] : [];
    }

    /**
     * Determine whether the table carries an index of the given name.
     *
     * Case-insensitive, since an engine may fold the name it reports.
     *
     * @param  string  $declared
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @return bool
     */
    private function carries(string $declared, array $indexes): bool
    {
        foreach ($indexes as $index) {

            if (strcasecmp($index->name, $declared) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an index holding an order leads with the column.
     *
     * @param  string  $column
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility  $eligibility
     * @return bool
     */
    private function ledByAnOrderedIndex(string $column, array $indexes, IndexEligibility $eligibility): bool
    {
        foreach ($indexes as $index) {

            if (!$eligibility->describes($index->name) || $eligibility->lacksColumnOrder($index->name)) {
                continue;
            }

            if ($index->leadsWith($column) && $this->holdsAnOrder($index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the index holds the order an ordered read needs.
     *
     * A connection naming no kind reports only ordered indexes.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition  $index
     * @return bool
     */
    private function holdsAnOrder(IndexDefinition $index): bool
    {
        return $index->type === null || $index->type === self::ORDERED_INDEX;
    }
}
