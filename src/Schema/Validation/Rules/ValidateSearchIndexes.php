<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Search\IndexProofWaiver;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;

/**
 * Validate that an index behind the connection serves every searchable field.
 *
 * An unreadable catalogue passes here; the request path proves it again.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidateSearchIndexes implements SchemaValidationRule
{
    /**
     * Create a new search index validation rule.
     *
     * @param  \SineMacula\ApiToolkit\Search\SearchDriverRegistry  $drivers
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @return void
     */
    public function __construct(

        /** Resolves the search driver serving the model's connection */
        private SearchDriverRegistry $drivers,

        /** Says whether the catalogue behind the model's table can be read */
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

        $connection = $model->getConnection();
        $name       = $connection->getDriverName();
        $driver     = $this->drivers->has($name) ? $this->drivers->resolve($name) : null;

        $defects = $driver === null
            ? $this->missingDriverDefects($declared, $name)
            : $this->merge(
                $this->combinationDefects($driver, $declared, $name),
                $this->strategyDefects($driver, $declared, $model, $connection),
            );

        return $this->report($resourceClass, $declared, $defects);
    }

    /**
     * Return the declared searchable columns and strategies, by field key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>
     */
    private function declaredFields(CompiledSchema $schema): array
    {
        $declared = [];

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null || $field->searchable === null || $field->searchStrategy === null) {
                continue;
            }

            $declared[$key] = ['column' => $field->searchable, 'strategy' => $field->searchStrategy];
        }

        return $declared;
    }

    /**
     * Turn the per-field defects into validation errors, in declaration order.
     *
     * @param  string  $resourceClass
     * @param  array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>  $declared
     * @param  array<string, array<int, string>>  $defects
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function report(string $resourceClass, array $declared, array $defects): array
    {
        $errors = [];

        foreach (array_keys($declared) as $key) {

            foreach ($defects[$key] ?? [] as $defect) {
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
     * Report every declared field against a connection no driver serves.
     *
     * @param  array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>  $declared
     * @param  string  $connection
     * @return array<string, array<int, string>>
     */
    private function missingDriverDefects(array $declared, string $connection): array
    {
        $defects = [];

        foreach ($declared as $key => $field) {
            $defects[$key] = [sprintf(
                'Field is declared searchable against "%s", and no search driver is registered for the "%s" connection to serve it',
                $field['column'],
                $connection,
            )];
        }

        return $defects;
    }

    /**
     * Report a strategy combination defect against the first declared field.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>  $declared
     * @param  string  $connection
     * @return array<string, array<int, string>>
     */
    private function combinationDefects(SearchDriver $driver, array $declared, string $connection): array
    {
        $defect = $driver->combinationDefect($this->strategies($declared));
        $first  = array_key_first($declared);

        if ($defect === null || $first === null) {
            return [];
        }

        return [$first => [sprintf(
            'The search surface cannot be served from an index on the "%s" connection, because %s',
            $connection,
            $defect,
        )]];
    }

    /**
     * Report what each strategy is missing, proving its columns together.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>  $declared
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    private function strategyDefects(SearchDriver $driver, array $declared, Model $model, Connection $connection): array
    {
        $defects = [];

        foreach ($this->strategies($declared) as $strategy) {

            $columns = $this->columnsFor($declared, $strategy);
            $found   = $this->defects($driver, $strategy, $columns, $model, $connection);

            foreach ($declared as $key => $field) {

                if ($field['strategy'] !== $strategy) {
                    continue;
                }

                foreach ($found[$field['column']] ?? [] as $defect) {
                    $defects[$key][] = $defect;
                }
            }
        }

        return $defects;
    }

    /**
     * Merge two field-keyed defect maps, keeping the defects both carry.
     *
     * @param  array<string, array<int, string>>  $first
     * @param  array<string, array<int, string>>  $second
     * @return array<string, array<int, string>>
     */
    private function merge(array $first, array $second): array
    {
        foreach ($second as $key => $defects) {
            $first[$key] = array_merge($first[$key] ?? [], $defects);
        }

        return $first;
    }

    /**
     * Return, keyed by column, why the strategy's columns are not index-served.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    private function defects(SearchDriver $driver, SearchStrategy $strategy, array $columns, Model $model, Connection $connection): array
    {
        $name           = $connection->getDriverName();
        $connectionName = $connection->getName();

        if (!in_array($strategy, $driver->supportedStrategies(), true)) {
            return array_fill_keys($columns, [sprintf(
                'Field is declared searchable with the "%s" strategy, which the driver serving the "%s" engine does not implement',
                $strategy->value,
                $name,
            )]);
        }

        if ($driver->canVerifyIndexBacking($strategy, $connection)) {
            return $this->proof($driver, $strategy, $columns, $model, $connection);
        }

        return IndexProofWaiver::waives($connectionName) ? [] : array_fill_keys($columns, [sprintf(
            'Field is declared searchable with the "%s" strategy, and the driver serving the "%s" connection cannot prove an index serves it. '
            . 'List that connection under api-toolkit.search.unverified_connections to serve it regardless',
            $strategy->value,
            $connectionName ?? $name,
        )]);
    }

    /**
     * Ask the driver for the proof, or nothing if the catalogue is unreadable.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    private function proof(SearchDriver $driver, SearchStrategy $strategy, array $columns, Model $model, Connection $connection): array
    {
        // A null catalogue is unknown, so it proves nothing
        if ($this->introspector->getIndexes($model) === null) {
            return [];
        }

        try {
            return $driver->indexDefects($strategy, $columns, $model->getTable(), $connection);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return the distinct declared strategies, in declaration order.
     *
     * @param  array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>  $declared
     * @return array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>
     */
    private function strategies(array $declared): array
    {
        $strategies = [];

        foreach ($declared as $field) {

            if (in_array($field['strategy'], $strategies, true)) {
                continue;
            }

            $strategies[] = $field['strategy'];
        }

        return $strategies;
    }

    /**
     * Return the distinct columns declared with the strategy, in order.
     *
     * @param  array<string, array{column: string, strategy: \SineMacula\ApiToolkit\Enums\SearchStrategy}>  $declared
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return array<int, string>
     */
    private function columnsFor(array $declared, SearchStrategy $strategy): array
    {
        $columns = [];

        foreach ($declared as $field) {

            if ($field['strategy'] !== $strategy || in_array($field['column'], $columns, true)) {
                continue;
            }

            $columns[] = $field['column'];
        }

        return $columns;
    }
}
