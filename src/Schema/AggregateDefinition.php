<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Schema\Concerns\HasMetricModifiers;

/**
 * Abstract base for relation aggregate (sum / average) schema definitions.
 *
 * Holds the common fluent API for building aggregate DSL entries and
 * serialising them to the raw schema array consumed by SchemaCompiler. Concrete
 * subclasses declare their metric identifier via {@see metric()}.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class AggregateDefinition extends BaseDefinition
{
    use HasMetricModifiers;

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

        // Optional alias to expose this metric under
        ?string $alias = null,
    ) {
        $this->alias = $alias;
    }

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
                'key'        => $present,
                'metric'     => static::metric(),
                'relation'   => $this->relation,
                'column'     => $this->column,
                'constraint' => $this->constraint,
                'default'    => $this->isDefault ? true : null,
                'extras'     => $this->extras ?: null,
                ...$this->commonAttributes(),
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
