<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Applies the named scopes a filter document invokes through `$scope`.
 *
 * Each scope runs in a where group of its own on its validated arguments, bound
 * to its parameters by name, and may only add where clauses to that group.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ScopeApplier
{
    /**
     * Apply the scopes the value invokes, ANDed together.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function apply(Builder $query, mixed $value, FilterContext $context, QuerySurface $surface): void
    {
        foreach ($this->invocations($value, $context->path()) as $name => $arguments) {

            $name = (string) $name;

            $context->admit($name);

            $position   = $context->at($name)->path();
            $definition = $surface->scopeFor($name) ?? throw Rejection::at($position, 'filters.undeclared_scope', ['scope' => $name]);
            $validated  = $this->validate($definition, $arguments, $position, $query->getModel());

            $query->where(fn (Builder $group) => $this->run($group, $definition, $validated, $position));
        }
    }

    /**
     * Return the invoked scope names mapped to their arguments.
     *
     * @param  mixed  $value
     * @param  string  $position
     * @return array<array-key, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function invocations(mixed $value, string $position): array
    {
        if (is_string($value)) {
            return [$value => []];
        }

        if (!is_array($value) || $value === [] || (array_is_list($value) && array_filter($value, is_string(...)) !== $value)) {
            throw Rejection::at($position, 'filters.scope_shape', ['position' => $position]);
        }

        return array_is_list($value) ? array_fill_keys($value, []) : $value;
    }

    /**
     * Validate the arguments against the scope's rules and parameters.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $definition
     * @param  mixed  $arguments
     * @param  string  $position
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function validate(CompiledScopeDefinition $definition, mixed $arguments, string $position, Model $model): array
    {
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw Rejection::at($position, 'filters.scope_arguments', ['scope' => $definition->name]);
        }

        foreach (array_keys($arguments) as $argument) {
            if (!in_array((string) $argument, $definition->arguments, true)) {
                throw $this->rejectArgument('filters.undeclared_argument', (string) $argument, $definition, $position);
            }
        }

        $validator = Validator::make($arguments, $definition->parameters);

        if ($validator->fails()) {
            throw $this->rekeyed($validator->errors()->messages(), $position);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $this->assertBindable($definition, $validated, $position, $model);

        return $validated;
    }

    /**
     * Assert that the validated arguments bind to the scope's parameters, which
     * a sometimes or exclude rule can leave unfilled.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $definition
     * @param  array<string, mixed>  $validated
     * @param  string  $position
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertBindable(CompiledScopeDefinition $definition, array $validated, string $position, Model $model): void
    {
        $method = $definition->method($model::class)
            ?? throw new \LogicException(sprintf('The "%s" scope is not defined on %s.', $definition->scope, $model::class));

        $unmatched = $validated;

        foreach (array_slice($method->getParameters(), 1) as $parameter) {

            if ($parameter->isVariadic()) {
                $this->assertNullsAdmitted($parameter, $unmatched, $definition, $position);
                return;
            }

            $argument = $parameter->getName();

            if (array_key_exists($argument, $validated)) {
                $this->assertNullsAdmitted($parameter, [$argument => $validated[$argument]], $definition, $position);
                unset($unmatched[$argument]);
                continue;
            }

            if (!$parameter->isDefaultValueAvailable()) {
                throw $this->rejectArgument('filters.missing_argument', $argument, $definition, $position);
            }
        }
    }

    /**
     * Assert that the parameter admits each null among the given arguments.
     *
     * @param  \ReflectionParameter  $parameter
     * @param  array<string, mixed>  $arguments
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $definition
     * @param  string  $position
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertNullsAdmitted(\ReflectionParameter $parameter, array $arguments, CompiledScopeDefinition $definition, string $position): void
    {
        if ($parameter->getType()?->allowsNull() ?? true) {
            return;
        }

        foreach ($arguments as $argument => $value) {
            if ($value === null) {
                throw $this->rejectArgument('filters.null_argument', $argument, $definition, $position);
            }
        }
    }

    /**
     * Build the rejection of one argument of the scope.
     *
     * @param  string  $key
     * @param  string  $argument
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $definition
     * @param  string  $position
     * @return \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function rejectArgument(string $key, string $argument, CompiledScopeDefinition $definition, string $position): Rejection
    {
        return Rejection::at($position . '.' . $argument, $key, ['argument' => $argument, 'scope' => $definition->name]);
    }

    /**
     * Run the scope on its group, refusing anything beyond where clauses.
     *
     * Checked before Eloquent merges the group's eager loads and removed global
     * scopes outward and drops all but its wheres. Eloquent drops an empty
     * group too, so a scope adding nothing matches every row explicitly.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $group
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $definition
     * @param  array<string, mixed>  $arguments
     * @param  string  $position
     * @return void
     *
     * @throws \LogicException
     */
    private function run(Builder $group, CompiledScopeDefinition $definition, array $arguments, string $position): void
    {
        $before = clone $group;

        try {
            $result = $group->scopes([$definition->scope => $arguments]);
        } catch (ValidationException $exception) { // @phpstan-ignore catch.neverThrown
            throw $this->rekeyed($exception->errors(), $position);
        }

        $violation = $this->violation($before, $group, $result);

        if ($violation !== null) {
            throw new \LogicException(sprintf('The "%s" scope on %s may only add where clauses, but it %s.', $definition->scope, $group->getModel()::class, $violation));
        }

        if ($group->getQuery()->wheres !== []) {
            return;
        }

        $group->whereRaw('1 = 1');
    }

    /**
     * Describe how the scope reached past its group's where clauses, if it did.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $before
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $after
     * @param  mixed  $result
     * @return string|null
     */
    private function violation(Builder $before, Builder $after, mixed $result): ?string
    {
        $reached = [
            'returned another builder' => $result                 !== $after,
            'added eager loads'        => $after->getEagerLoads() !== $before->getEagerLoads(),
            'removed global scopes'    => $after->removedScopes() !== $before->removedScopes(),
        ];

        return array_search(true, $reached, true) ?: $this->changedProperty($before->getQuery(), $after->getQuery());
    }

    /**
     * Name the first query property the scope changed other than its where
     * clauses and their bindings, if any.
     *
     * @param  \Illuminate\Database\Query\Builder  $before
     * @param  \Illuminate\Database\Query\Builder  $after
     * @return string|null
     */
    private function changedProperty(QueryBuilder $before, QueryBuilder $after): ?string
    {
        $original = get_object_vars($before);

        foreach (array_diff_key(get_object_vars($after), ['wheres' => true]) as $property => $value) {

            $previous = $original[$property] ?? null;

            if ($property === 'bindings') {
                unset($value['where'], $previous['where']);
            }

            if ($value !== $previous) {
                return sprintf('changed the query\'s %s', $property);
            }
        }

        return null;
    }

    /**
     * Key the given validation messages under the scope's position.
     *
     * @param  array<string, array<int, string>>  $messages
     * @param  string  $position
     * @return \Illuminate\Validation\ValidationException
     */
    private function rekeyed(array $messages, string $position): ValidationException
    {
        $keyed = [];

        foreach ($messages as $key => $lines) {
            $keyed[$position . '.' . $key] = $lines;
        }

        return ValidationException::withMessages($keyed);
    }
}
