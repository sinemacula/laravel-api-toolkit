<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Explains why the catalogue behind a model's table could not be read.
 *
 * Reads the column catalogue as the introspector does, through the writer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CatalogueProbe
{
    /**
     * Describe why the model's catalogue could not be read.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    public function describe(Model $model): string
    {
        $name  = $this->connectionName($model);
        $table = $model->getTable();

        try {
            $connection = $model->getConnection();
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            return sprintf('connection "%s" cannot be resolved: %s', $name, $exception->getMessage());
        }

        try {
            $columns = $connection->getSchemaBuilder()->getColumns($table);
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            return sprintf('the catalogue of table "%s" on connection "%s" cannot be read: %s', $table, $name, $exception->getMessage());
        }

        return $columns === []
            ? sprintf('table "%s" was not found on connection "%s"; it is not migrated, or not visible to this user', $table, $name)
            : sprintf('the catalogue of table "%s" on connection "%s" could not be read during validation', $table, $name);
    }

    /**
     * Return the name of the model's connection, or the default connection's.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    private function connectionName(Model $model): string
    {
        $name = $model->getConnectionName() ?? Config::get('database.default');

        return is_string($name) ? $name : '';
    }
}
