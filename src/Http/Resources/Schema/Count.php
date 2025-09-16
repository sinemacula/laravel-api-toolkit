<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Closure;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Count schema helper for metric definitions.
 *
 * @author      Ben Carey <ben.carey@verifast.com>
 * @copyright   2025 Sine Macula Limited.
 */
final class Count extends BaseDefinition implements Arrayable
{
    /** @var Closure|null Optional eager-load constraint for this count */
    private ?Closure $constraint = null;

    /** @var bool Whether this count should be included by default when metrics are requested */
    private bool $isDefault = false;

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $name
     * @param  string|null  $alias
     */
    private function __construct(

        /** Canonical metric key (typically a relation alias) */
        private readonly string $name,

        /** Optional alias to expose this metric under */
        private ?string $alias = null

    ) {}

    /**
     * Define a count metric by key.
     *
     * @param  string  $key
     * @param  string|null  $alias
     * @return self
     */
    public static function of(string $key, ?string $alias = null): self
    {
        return new self($key, $alias);
    }

    /**
     * Set or change the alias for this metric.
     *
     * @param  string  $alias
     * @return self
     */
    public function as(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Apply an optional query constraint to this count.
     *
     * @param  Closure  $constraint
     * @return self
     */
    public function constrain(Closure $constraint): self
    {
        $this->constraint = $constraint;

        return $this;
    }

    /**
     * Mark this count as a default metric when metrics are requested without
     * explicit count selections.
     *
     * @return self
     */
    public function default(): self
    {
        $this->isDefault = true;

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $key = $this->alias ?? $this->name;

        return [
            $key => array_filter([
                'metric'       => 'count',
                'relation'     => $this->name,
                'constraint'   => $this->constraint,
                'default'      => $this->isDefault ? true : null,
                'extras'       => $this->extras ?: null,
                'guards'       => $this->getGuards() ?: null,
                'transformers' => $this->getTransformers() ?: null
            ], static fn ($value) => $value !== null)
        ];
    }
}
