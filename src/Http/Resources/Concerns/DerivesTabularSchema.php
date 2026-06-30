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
 * Derives a tabular export schema from the resource's compiled field
 * definitions.
 *
 * Intended for use on {@see \SineMacula\ApiToolkit\Http\Resources\ApiResource}
 * subclasses that also implement
 * {@see \SineMacula\Exporter\Contracts\ProvidesTabularExport}. When mixed in,
 * the `tabular()` method is satisfied for free: it compiles the resource schema
 * and maps each non-relation field to a typed Column, resolving each cell
 * through a fresh resource so the field's compute, accessor, and transformers
 * are honoured exactly as the JSON response applies them.
 *
 * Field guards are honoured at the column level. A guard that depends only on
 * the request maps to the exporter's column-visibility gate, so the whole
 * column is omitted when the guard hides the field. A guard that inspects the
 * row cannot be honoured by a flat tabular schema - the exporter drops whole
 * columns, not individual cells - so such a field is refused at build time to
 * avoid leaking the values the guard is meant to hide.
 *
 * The schema returned is request-aware so column visibility and field selection
 * can be narrowed by the active API query.
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
     * Compiles the resource schema and builds one Column per non-relation
     * field. When a field set is active on the current API query, only the
     * requested fields are included, mirroring what the JSON response returns.
     * Relation fields are skipped because they cannot be represented as scalar
     * cells without an explicit expansion policy.
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
     * Resolves the cell value (with transformers) and then applies the field's
     * guards to the column.
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
     * Fields with a compute, a callable accessor, or transformers resolve
     * their value through a fresh resource so the JSON value, transformers
     * included, is mirrored in the cell. Plain scalar and string-accessor
     * fields without transformers keep the exporter's raw attribute read.
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
     * Delegates to the same value resolver the JSON response uses, so compute,
     * accessor, and transformer logic produce identical values. A MissingValue
     * (an absent attribute or unmet guard) collapses to null so the column's
     * null/default policy applies.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @return \Closure
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
     * A field with no guards is returned untouched. A guard that inspects
     * the row cannot gate a flat column, so the field is refused. Guards
     * that depend only on the request map to the column-visibility gate.
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
     * Determine whether any guard depends on the row rather than the request.
     *
     * Each guard is invoked with a probe in place of the resource. A guard that
     * touches the probe, or errors when handed it, is treating the row as
     * load-bearing and is reported as per-item. Failing closed, an
     * unclassifiable guard is treated as per-item.
     *
     * @param  array<int, mixed>  $guards
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function hasPerItemGuard(array $guards, Request $request): bool
    {
        foreach ($guards as $guard) {
            if (is_callable($guard) && $this->probeGuard($guard, $request)[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate the request-scoped guards for column visibility.
     *
     * Mirrors the JSON guard semantics: a guard suppresses the column only when
     * it returns exactly false. A guard that touches the row, or errors, hides
     * the column rather than risk exposing it.
     *
     * @param  array<int, mixed>  $guards
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function passesRequestGuards(array $guards, Request $request): bool
    {
        foreach ($guards as $guard) {

            if (!is_callable($guard)) {
                continue;
            }

            [$touchedOrThrew, $result] = $this->probeGuard($guard, $request);

            if ($touchedOrThrew || $result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Invoke a guard with a probe row and report what it did.
     *
     * Returns a two-element tuple: whether the guard touched the probe or
     * threw, and the value it returned (false when it threw).
     *
     * @param  callable  $guard
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
