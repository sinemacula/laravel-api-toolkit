<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\Concerns\HasMetricModifiers;

/**
 * Base for every relation aggregate schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class AggregateDefinition extends BaseDefinition
{
    use HasMetricModifiers;

    /** @var string The prefix every aggregate's raw schema key carries */
    public const string KEY_PREFIX = '__aggregate__:';

    /**
     * Prevent direct instantiation; use of() on the concrete subclass.
     *
     * @param  string  $relation
     * @param  string|null  $column
     * @param  string|null  $alias
     */
    final protected function __construct(

        /** The Eloquent relation to aggregate */
        private readonly string $relation,

        /** The related column to aggregate, or null for count and exists */
        private readonly ?string $column = null,

        // Optional alias to expose this aggregate under
        ?string $alias = null,
    ) {
        $this->alias = $alias;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(): array
    {
        $function = $this->function();
        $present  = $this->alias ?? $function->defaultKey($this->relation, $this->column);

        return [
            self::KEY_PREFIX . $present => array_filter([
                'key'        => $present,
                'metric'     => $function->value,
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
     * Return the SQL function this aggregate applies.
     *
     * @return \SineMacula\ApiToolkit\Enums\AggregateFunction
     */
    abstract public function function(): AggregateFunction;
}
