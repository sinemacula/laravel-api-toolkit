<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Services\Input\Rules\Enum;
use SineMacula\ApiToolkit\Support\PureEnumName;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Checks a filter value against its operator's shape and its column's type, and
 * resolves it to the value the operator binds.
 *
 * A value that does not fit is refused, as dropping, coercing or reinterpreting
 * it would change which rows match.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FilterValueGuard
{
    /**
     * Create a new filter value guard.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral  $literal
     */
    public function __construct(

        /** Checks and resolves each scalar against the column's storage type */
        private ColumnLiteral $literal,
    ) {}

    /**
     * Resolve the value given to the operator on the model's column, refusing
     * it at the given position when it does not fit.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  string  $operator
     * @param  \SineMacula\ApiToolkit\Enums\FilterValueShape  $shape
     * @param  mixed  $value
     * @param  string  $position
     * @return mixed
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    public function resolve(Model $model, string $column, string $operator, FilterValueShape $shape, mixed $value, string $position): mixed
    {
        $condition = ['position' => $position, 'column' => $column, 'operator' => $operator];

        return match ($shape) {
            FilterValueShape::SCALAR   => $this->resolveScalar($model, $condition, $value),
            FilterValueShape::LIST     => $this->resolveItems($model, $condition, $value, null),
            FilterValueShape::BOUNDS   => $this->resolveItems($model, $condition, $value, 2),
            FilterValueShape::PRESENCE => $value === true ? $value : throw $this->reject($condition, 'filters.value_presence'),
        };
    }

    /**
     * Resolve a value that must be one scalar.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array{position: string, column: string, operator: string}  $condition
     * @param  mixed  $value
     * @return bool|float|int|string
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function resolveScalar(Model $model, array $condition, mixed $value): bool|float|int|string
    {
        if ($value === null) {
            throw $this->reject($condition, 'filters.value_null');
        }

        if (!is_scalar($value)) {
            throw $this->reject($condition, 'filters.value_scalar');
        }

        return $this->resolveItem($model, $condition, $value, null);
    }

    /**
     * Resolve a value that must be a list of scalars, of the given length when
     * one is required.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array{position: string, column: string, operator: string}  $condition
     * @param  mixed  $value
     * @param  int|null  $length
     * @return array<int, bool|float|int|string>
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function resolveItems(Model $model, array $condition, mixed $value, ?int $length): array
    {
        if (!is_array($value) || !array_is_list($value) || ($length !== null && count($value) !== $length)) {
            throw $this->reject($condition, $length === null ? 'filters.value_list' : 'filters.value_bounds');
        }

        return array_map(fn (mixed $item, int $index): bool|float|int|string => $this->resolveItem($model, $condition, $item, $index), $value, array_keys($value));
    }

    /**
     * Resolve one scalar of the value, located by its index within a list.
     *
     * An enum cast is checked before the storage type, since it names the only
     * values the column holds.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array{position: string, column: string, operator: string}  $condition
     * @param  mixed  $item
     * @param  int|null  $index
     * @return bool|float|int|string
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function resolveItem(Model $model, array $condition, mixed $item, ?int $index): bool|float|int|string
    {
        if (!is_scalar($item)) {
            throw $this->rejectType($condition, $index, Rejection::message('filters.expected.scalar'));
        }

        $enum     = $this->castEnum($model, $condition['column']);
        $expected = $enum === null ? $this->literal->mismatch($model, $condition['column'], $item) : $this->enumMismatch($enum, $item);

        if ($expected !== null) {
            throw $this->rejectType($condition, $index, $expected);
        }

        return $enum === null ? $this->literal->resolve($model, $condition['column'], $item) : $item;
    }

    /**
     * Return the enum the column is cast to, unless a caster of its own reads
     * it, as Eloquent defers to one.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return class-string<\UnitEnum>|null
     */
    private function castEnum(Model $model, string $column): ?string
    {
        $cast = $model->getCasts()[$column] ?? null;

        return is_string($cast) && enum_exists($cast) && !is_subclass_of($cast, Castable::class) ? $cast : null;
    }

    /**
     * Describe the values the enum accepts, in the form a client sends them, or
     * return null when the value names one of its cases.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @param  bool|float|int|string  $value
     * @return string|null
     *
     * @throws \LogicException
     */
    private function enumMismatch(string $enum, bool|float|int|string $value): ?string
    {
        if (Enum::resolve($enum, $value) !== null) {
            return null;
        }

        $values = array_map(static fn (\UnitEnum $case): string => $case instanceof \BackedEnum ? (string) $case->value : PureEnumName::of($case), $enum::cases());

        return Rejection::message('filters.expected.enum', ['values' => implode(', ', $values)]);
    }

    /**
     * Build the rejection of a value, or of the item at the index of a list,
     * that is not of the expected type.
     *
     * @param  array{position: string, column: string, operator: string}  $condition
     * @param  int|null  $index
     * @param  string  $expected
     * @return \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function rejectType(array $condition, ?int $index, string $expected): Rejection
    {
        return $index === null
            ? $this->reject($condition, 'filters.value_type', ['expected' => $expected])
            : $this->reject($condition, 'filters.item_type', ['expected' => $expected, 'index' => $index]);
    }

    /**
     * Build the rejection of a condition's value at its position.
     *
     * @param  array{position: string, column: string, operator: string}  $condition
     * @param  string  $key
     * @param  array<string, int|string>  $replace
     * @return \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function reject(array $condition, string $key, array $replace = []): Rejection
    {
        return Rejection::at($condition['position'], $key, ['operator' => $condition['operator'], 'column' => $condition['column'], ...$replace]);
    }
}
