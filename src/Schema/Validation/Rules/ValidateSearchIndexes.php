<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
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
 * A declared match strategy is a claim about the index the column carries, and
 * the claim is only worth what the connection can be made to confirm. This rule
 * asks the driver serving the model's connection to confirm it, so a column
 * declared with a strategy no index answers fails the build rather than the
 * first request that searches it.
 *
 * The check reads the connection's catalogue, which is why it lives here rather
 * than in the request path: the proof is paid once, at boot or in a build, not
 * on every search. A resource declaring nothing searchable is left alone and
 * the connection is never touched.
 *
 * A driver that cannot inspect its connection has proved nothing. That is
 * reported unless the connection is one where the proof is waived, so the
 * development connection a suite runs against stays quiet while a connection
 * serving traffic does not.
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
     * @return void
     */
    public function __construct(

        /** Resolves the search driver serving the model's connection */
        private SearchDriverRegistry $drivers,
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
        if ($schema->getSearchableColumns() === [] || $modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;

        $connection = $model->getConnection();
        $table      = $model->getTable();
        $name       = $connection->getDriverName();
        $driver     = $this->drivers->has($name) ? $this->drivers->resolve($name) : null;

        $errors = [];

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null || $field->searchable === null || $field->searchStrategy === null) {
                continue;
            }

            foreach ($this->defects($driver, $field->searchStrategy, $field->searchable, $table, $connection) as $defect) {
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
     * Return every reason the declared strategy is not served from an index on
     * this connection.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver|null  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function defects(?SearchDriver $driver, SearchStrategy $strategy, string $column, string $table, Connection $connection): array
    {
        $name = $connection->getDriverName();

        if ($driver === null) {
            return [sprintf(
                'Field is declared searchable against "%s", and no search driver is registered for the "%s" connection to serve it',
                $column,
                $name,
            )];
        }

        if (!in_array($strategy, $driver->supportedStrategies(), true)) {
            return [sprintf(
                'Field is declared searchable with the "%s" strategy, which the driver registered for the "%s" connection does not implement',
                $strategy->value,
                $name,
            )];
        }

        return $driver->canVerifyIndexBacking($strategy, $connection)
            ? $this->proof($driver, $strategy, $column, $table, $connection)
            : $this->unproven($strategy, $name);
    }

    /**
     * Ask the driver for the proof, reporting a connection that could not be
     * read rather than reading silence as a proof.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function proof(SearchDriver $driver, SearchStrategy $strategy, string $column, string $table, Connection $connection): array
    {
        try {
            return $driver->indexDefects($strategy, $column, $table, $connection);
        } catch (\Throwable $exception) {
            return [sprintf(
                'Field is declared searchable against "%s", and the "%s" connection could not be read to prove an index serves it: %s',
                $column,
                $connection->getDriverName(),
                $exception->getMessage(),
            )];
        }
    }

    /**
     * Report a declaration no index is known to serve, unless the connection
     * waives the proof.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $connection
     * @return array<int, string>
     */
    private function unproven(SearchStrategy $strategy, string $connection): array
    {
        if (IndexProofWaiver::waives($connection)) {
            return [];
        }

        return [sprintf(
            'Field is declared searchable with the "%s" strategy, and the driver registered for the "%s" connection cannot prove an index serves it',
            $strategy->value,
            $connection,
        )];
    }
}
