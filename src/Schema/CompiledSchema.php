<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Typed value object holding compiled field and aggregate definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledSchema
{
    /**
     * Create a new compiled schema.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>  $fields
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition>  $aggregates
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterableColumns
     * @param  array<int, string>  $sortableColumns
     * @param  array<int, string>  $traversableRelations
     * @param  array<string, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $searchableColumns
     */
    public function __construct(

        /** The compiled field definitions keyed by field name */
        private array $fields,

        /** The compiled aggregate definitions keyed by present key */
        private array $aggregates = [],

        /** Declared filterable column names mapped to their capability */
        private array $filterableColumns = [],

        /** Declared sortable column names */
        private array $sortableColumns = [],

        /** Declared externally-traversable relation names */
        private array $traversableRelations = [],

        /** Declared searchable column names mapped to their match strategy */
        private array $searchableColumns = [],
    ) {}

    /**
     * Return the compiled field definition for the given key, or null.
     *
     * @param  string  $key
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition|null
     */
    public function getField(string $key): ?CompiledFieldDefinition
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * Return all field keys, excluding aggregate definitions.
     *
     * @return array<int, string>
     */
    public function getFieldKeys(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Return the aggregate definitions keyed by present key.
     *
     * @return array<string, \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition>
     */
    public function getAggregateDefinitions(): array
    {
        return $this->aggregates;
    }

    /**
     * Return the declared filterable columns mapped to their capability.
     *
     * @return array<string, \SineMacula\ApiToolkit\Enums\Capability>
     */
    public function getFilterableColumns(): array
    {
        return $this->filterableColumns;
    }

    /**
     * Return the declared sortable column names.
     *
     * @return array<int, string>
     */
    public function getSortableColumns(): array
    {
        return $this->sortableColumns;
    }

    /**
     * Return the declared externally-traversable relation names.
     *
     * @return array<int, string>
     */
    public function getTraversableRelations(): array
    {
        return $this->traversableRelations;
    }

    /**
     * Return the declared searchable columns mapped to their match strategy.
     *
     * @return array<string, \SineMacula\ApiToolkit\Enums\SearchStrategy>
     */
    public function getSearchableColumns(): array
    {
        return $this->searchableColumns;
    }
}
