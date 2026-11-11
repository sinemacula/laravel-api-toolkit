<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Derives a tabular export schema from the resource's compiled fields.
 *
 * Row-reading guards are refused: the exporter can only drop whole columns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-require-extends \SineMacula\ApiToolkit\Http\Resources\ApiResource
 */
trait DerivesTabularSchema
{
    /**
     * Derive the tabular schema from the resource's compiled field definitions.
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

        $schema    = SchemaCompiler::compile($resourceClass);
        $requested = ApiQuery::getFields(static::getResourceType());
        $allKeys   = $schema->getFieldKeys();
        $fieldKeys = $requested !== null ? array_values(array_intersect($requested, $allKeys)) : $allKeys;

        $columns = [];

        foreach ($fieldKeys as $key) {

            $definition = $schema->getField($key);

            if ($definition === null || $definition->relation !== null) {
                continue;
            }

            $columns[] = $this->buildTabularColumn($key, $definition, $resourceClass, $request);
        }

        return new DerivedTabularSchema($request, $columns);
    }

    /**
     * Build a single tabular column for the given field definition.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\Column
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException
     */
    private function buildTabularColumn(string $key, CompiledFieldDefinition $definition, string $resourceClass, Request $request): Column
    {
        return $this->applyGuards($key, $definition, $this->baseColumn($key, $definition, $resourceClass), $request);
    }

    /**
     * Build the value-resolving column for the field, before guards.
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

        if (is_string($definition->accessor)) {
            return Column::make($key)->fromModel($definition->accessor);
        }

        return Column::make($key);
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
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\Column
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException
     */
    private function applyGuards(string $key, CompiledFieldDefinition $definition, Column $column, Request $request): Column
    {
        $guards = $definition->guards;

        if ($guards === []) {
            return $column;
        }

        if ($this->hasPerItemGuard($guards, $request)) {
            throw PerItemGuardedFieldException::forField($key, static::getResourceType());
        }

        return $column->visible(fn (Request $request): bool => $this->passesRequestGuards($guards, $request));
    }

    /**
     * Determine whether any guard reads the row (or throws when probed).
     *
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function hasPerItemGuard(array $guards, Request $request): bool
    {
        foreach ($guards as $guard) {
            if ($this->probeGuard($guard, $request)[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate the request-scoped guards for column visibility.
     *
     * A guard that touches the row or throws hides the column.
     *
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function passesRequestGuards(array $guards, Request $request): bool
    {
        foreach ($guards as $guard) {

            [$touchedOrThrew, $result] = $this->probeGuard($guard, $request);

            if ($touchedOrThrew || $result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Probe a guard: whether it touched the row (or threw), and its result.
     *
     * @param  callable(mixed, \Illuminate\Http\Request): mixed  $guard
     * @param  \Illuminate\Http\Request  $request
     * @return array{0: bool, 1: mixed}
     */
    private function probeGuard(callable $guard, Request $request): array
    {
        $probe = new RowGuardProbe;

        try {
            $result = $guard($probe, $request);
        } catch (\Throwable) {
            return [true, false];
        }

        return [$probe->wasTouched(), $result];
    }
}
