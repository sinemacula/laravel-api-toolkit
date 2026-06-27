<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Abstract base for relation aggregate (sum / average) schema definitions.
 *
 * Holds the common fluent API for building aggregate DSL entries and
 * serialising them to the raw schema array consumed by SchemaCompiler.
 * Concrete
 * subclasses declare their metric identifier via {@see metric()}.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \Illuminate\Contracts\Support\Arrayable<string, array<string, mixed>>
 */
abstract class AggregateDefinition extends BaseDefinition implements Arrayable
{
    /** @var (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null Optional eager-load constraint for this aggregate */
    private ?\Closure $constraint = null;

    /** @var bool Whether this aggregate is included by default when metrics are requested */
    private bool $isDefault = false;

    /**
     * Prevent direct instantiation; use of() on the concrete subclass.
     *
     * @param  string  $relation
     * @param  string  $column
     * @param  string|null  $alias
     */
    protected function __construct(

        /** The Eloquent relation to aggregate */
        private readonly string $relation,

        /** The database column to aggregate */
        private readonly string $column,

        /** Optional alias to expose this metric under */
        private ?string $alias = null,
    ) {}

    /**
     * Define an aggregate metric by relation and column.
     *
     * @param  string  $relation
     * @param  string  $column
     * @param  string|null  $alias
     * @return static
     */
    public static function of(string $relation, string $column, ?string $alias = null): static
    {
        return new static($relation, $column, $alias); // @phpstan-ignore new.static (Sum and Average never override the constructor)
    }

    /**
     * Set or change the alias for this metric.
     *
     * @param  string  $alias
     * @return static
     */
    public function as(string $alias): static
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Apply an optional query constraint to this aggregate.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void  $constraint
     * @return static
     */
    public function constrain(\Closure $constraint): static
    {
        $this->constraint = $constraint;

        return $this;
    }

    /**
     * Mark this aggregate as a default metric when metrics are requested
     * without explicit aggregate selections.
     *
     * @return static
     */
    public function default(): static
    {
        $this->isDefault = true;

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(): array
    {
        $present = $this->alias ?? ($this->relation . '_' . $this->column);
        $key     = '__' . static::metric() . '__:' . $present;

        return [
            $key => array_filter([
                'key'          => $present,
                'metric'       => static::metric(),
                'relation'     => $this->relation,
                'column'       => $this->column,
                'constraint'   => $this->constraint,
                'default'      => $this->isDefault ? true : null,
                'extras'       => $this->extras ?: null,
                'guards'       => $this->getGuards() ?: null,
                'transformers' => $this->getTransformers() ?: null,
                'openapi'      => $this->getOpenApiDeclaration()?->toSchema(),
            ], static fn ($value) => $value !== null),
        ];
    }

    /**
     * Return the metric identifier for this aggregate type.
     *
     * @return string
     */
    abstract protected static function metric(): string;
}
