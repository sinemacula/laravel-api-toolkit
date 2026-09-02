<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that an index the connection carries leads with every sortable
 * column.
 *
 * A sortable declaration is an offer to order the whole table by a column on
 * request, and the offer is only affordable where an index already holds that
 * order. The column has to lead the index rather than merely appear in it: only
 * the leading column is a key prefix, so an index naming a column second is
 * read in an order that column does not decide and cannot answer an ordered
 * read of it alone. Checking membership instead would pass exactly the
 * declaration the database cannot serve.
 *
 * The connection is the authority. Where it names an index kind, only a kind
 * that holds an order counts, so a full-text or trigram index over a column
 * does not make that column sortable. Where it names no kind, every index it
 * reports holds an order, so the absence is read as such rather than as a
 * disqualification.
 *
 * A connection that cannot be inspected at all is a different answer again, and
 * the rule stays silent for it: a developer booting without a database has
 * proved nothing, while a connection that was read and carries no index has
 * proved the declaration wrong. The catalogue is read here, during validation,
 * and never while a request is being served.
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
     * @return void
     */
    public function __construct(

        /** Reads the index catalogue behind the model's table */
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
        $declared = $this->declaredFields($schema);

        if ($declared === [] || $modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;

        $indexes = $this->introspector->getIndexes($model);
        $table   = $model->getTable();
        $errors  = [];

        foreach ($declared as $key => $field) {

            foreach ($this->defects($field, $indexes, $table) as $defect) {
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
     * Return the fields carrying a sortable declaration or an override of the
     * index behind one, keyed by field key.
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
     * @return array<int, string>
     */
    private function defects(CompiledFieldDefinition $field, ?array $indexes, string $table): array
    {
        if ($field->indexedBy !== null && $field->unindexedReason !== null) {
            return ['Field declares both a backing index and an index exemption, so neither governs the sort'];
        }

        if ($field->sortable === null) {
            return ['Field declares index backing but is not declared sortable, so the declaration governs nothing'];
        }

        // A null catalogue is the connection saying nothing, not saying empty.
        return $field->unindexedReason !== null || $indexes === null
            ? []
            : $this->backingDefects($field->sortable, $field->indexedBy, $indexes, $table);
    }

    /**
     * Describe what the sortable column is missing on the table, or return
     * nothing when an index the connection carries backs it.
     *
     * @param  string  $column
     * @param  string|null  $declared
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @param  string  $table
     * @return array<int, string>
     */
    private function backingDefects(string $column, ?string $declared, array $indexes, string $table): array
    {
        if ($declared !== null) {

            return $this->carries($declared, $indexes) ? [] : [sprintf(
                'Field declares the "%s" index behind sortable column "%s", and table "%s" carries no index of that name',
                $declared,
                $column,
                $table,
            )];
        }

        return $this->ledByAnOrderedIndex($column, $indexes) ? [] : [sprintf(
            'Field is declared sortable against "%s", and no ordered index on table "%s" leads with that column',
            $column,
            $table,
        )];
    }

    /**
     * Determine whether the table carries an index of the given name.
     *
     * The comparison ignores case because an engine may report a name folded to
     * its own, so a declaration matching the migration that created it is not
     * refused for the folding.
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
     * @return bool
     */
    private function ledByAnOrderedIndex(string $column, array $indexes): bool
    {
        foreach ($indexes as $index) {

            if ($index->leadsWith($column) && $this->holdsAnOrder($index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the index holds the order an ordered read needs.
     *
     * A connection naming no kind distinguishes none, and everything it reports
     * holds an order, so silence counts rather than disqualifies.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition  $index
     * @return bool
     */
    private function holdsAnOrder(IndexDefinition $index): bool
    {
        return $index->type === null || $index->type === self::ORDERED_INDEX;
    }
}
