<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Support\PureEnumName;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Derives a tabular export schema from the resource's compiled fields.
 *
 * Row guards are refused, as the exporter can only drop whole columns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-require-extends \SineMacula\ApiToolkit\Http\Resources\ApiResource
 */
trait DerivesTabularSchema
{
    /**
     * Derive the tabular schema from the fields the resource selects.
     *
     * Relation fields are skipped as they have no scalar cell form.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException
     */
    public function tabular(Request $request): TabularSchema
    {
        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $resourceClass */
        $resourceClass = static::class;

        $schema  = SchemaCompiler::compile($resourceClass);
        $columns = [];

        foreach ($this->selectedFields($schema) as $key) {

            $definition = $schema->getField($key);

            if ($definition === null || $definition->relation !== null) {
                continue;
            }

            $columns[] = $this->buildTabularColumn($key, $definition, $resourceClass);
        }

        return new DerivedTabularSchema($request, $columns);
    }

    /**
     * Build a single tabular column for the given field definition.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @return \SineMacula\Exporter\Schema\Column
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException
     */
    private function buildTabularColumn(string $key, CompiledFieldDefinition $definition, string $resourceClass): Column
    {
        return $this->applyGuards($key, $definition, $this->baseColumn($key, $definition, $resourceClass));
    }

    /**
     * Build the value-resolving column for the field, before guards.
     *
     * A plain field reads the attribute directly, with a pure enum written as
     * the lowercase name a response carries, since the exporter has no cell for
     * an enum without a value.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @return \SineMacula\Exporter\Schema\Column
     */
    private function baseColumn(string $key, CompiledFieldDefinition $definition, string $resourceClass): Column
    {
        if ($this->needsResourceResolution($definition)) {
            return Column::make($key)->resolveUsing($this->resourceValueResolver($key, $definition, $resourceClass));
        }

        $path = is_string($definition->accessor) ? $definition->accessor : $key;

        return Column::make($key)->resolveUsing(static function (array|object $item) use ($path): mixed {

            $value = data_get($item, $path);

            return $value instanceof \UnitEnum && !$value instanceof \BackedEnum ? PureEnumName::of($value) : $value;
        });
    }

    /**
     * Determine whether the field's value must be resolved through a resource.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return bool
     */
    private function needsResourceResolution(CompiledFieldDefinition $definition): bool
    {
        return $definition->transformers !== []
            || $definition->compute      !== null
            || is_callable($definition->accessor);
    }

    /**
     * Build the closure that resolves a cell value through a resource instance.
     *
     * A MissingValue collapses to null so the column's null policy applies.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @return \Closure(array<array-key, mixed>|object, \Illuminate\Http\Request): mixed
     */
    private function resourceValueResolver(string $key, CompiledFieldDefinition $definition, string $resourceClass): \Closure
    {
        $resolver = new ValueResolver(new GuardEvaluator);

        return static function ($item, $request) use ($resolver, $key, $definition, $resourceClass): mixed {
            $value = $resolver->resolveFieldValue($key, $definition, new $resourceClass($item), $request);

            return $value instanceof MissingValue ? null : $value;
        };
    }

    /**
     * Apply the field's guards to the column.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  \SineMacula\Exporter\Schema\Column  $column
     * @return \SineMacula\Exporter\Schema\Column
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException
     */
    private function applyGuards(string $key, CompiledFieldDefinition $definition, Column $column): Column
    {
        if ($definition->guards !== []) {
            throw PerItemGuardedFieldException::forField($key, static::getResourceType());
        }

        $guards = $definition->callerGuards;

        if ($guards === []) {
            return $column;
        }

        return $column->visible(static fn (Request $request): bool => (new GuardEvaluator)->passesCallerGuards($guards, $request));
    }
}
