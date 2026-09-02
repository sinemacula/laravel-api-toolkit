<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException;

/**
 * Field schema helpers for scalar and accessor fields.
 *
 * Provides guard and transformer support, optional aliasing, and Arrayable
 * definitions suitable for direct use in resource schemas.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Field extends BaseDefinition
{
    /** @var mixed Compute callable for dynamic field values */
    private mixed $compute = null;

    /** @var \SineMacula\ApiToolkit\Enums\Capability|null The capability this field's column is declared filterable with */
    private ?Capability $filterable = null;

    /** @var bool Whether this field's column is declared sortable */
    private bool $sortable = false;

    /** @var \SineMacula\ApiToolkit\Enums\SearchStrategy|null The strategy this field's column is declared searchable with */
    private ?SearchStrategy $searchable = null;

    /** @var string|null The index the author declares behind this field's sortable column */
    private ?string $indexed = null;

    /** @var string|null The reason this field's sortable column is deliberately left unindexed */
    private ?string $unindexed = null;

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $name
     * @param  mixed|null  $accessor
     * @param  string|null  $alias
     */
    private function __construct(

        /** The field's canonical name */
        private readonly string $name,

        /** Accessor for computed or nested values */
        private readonly mixed $accessor = null,

        /** Optional alias to expose this field under */
        private ?string $alias = null,
    ) {}

    /**
     * Define a scalar field by name.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return static
     */
    public static function scalar(string $field, ?string $alias = null): self
    {
        return new self($field, null, $alias);
    }

    /**
     * Define an accessor field by name.
     *
     * @param  string  $field
     * @param  (callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string  $accessor
     * @param  string|null  $alias
     * @return self
     */
    public static function accessor(string $field, callable|string $accessor, ?string $alias = null): self
    {
        return new self($field, $accessor, $alias);
    }

    /**
     * Define a timestamp field by name.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return self
     */
    public static function timestamp(string $field, ?string $alias = null): self
    {
        $definition = self::accessor($field, static function ($resource) use ($field): ?string {

            $value = data_get($resource, $field);

            return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
        }, $alias);

        $definition->openapi()->type('string')->format('date-time')->nullable();

        return $definition;
    }

    /**
     * Define a date field by name.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return self
     */
    public static function date(string $field, ?string $alias = null): self
    {
        $definition = self::accessor($field, static function ($resource) use ($field): ?string {

            $value = data_get($resource, $field);

            return $value instanceof CarbonInterface ? $value->toDateString() : null;
        }, $alias);

        $definition->openapi()->type('string')->format('date')->nullable();

        return $definition;
    }

    /**
     * Define a computed field by name.
     *
     * @param  string  $field
     * @param  (callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string  $compute
     * @param  string|null  $alias
     * @return self
     */
    public static function compute(string $field, callable|string $compute, ?string $alias = null): self
    {
        $instance          = new self($field, null, $alias);
        $instance->compute = $compute;

        return $instance;
    }

    /**
     * Set or change the alias for this field.
     *
     * @param  string  $alias
     * @return self
     */
    public function alias(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Declare this field's column as filterable with the given capability.
     *
     * The capability is explicit because it decides which operators the column
     * answers. A bare declaration would open every operator on every column,
     * including the containment and negation shapes that no index behind an
     * ordinary column can serve.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @return self
     */
    public function filterable(Capability $capability): self
    {
        $this->filterable = $capability;

        return $this;
    }

    /**
     * Declare this field's column as sortable.
     *
     * An offer to order the whole table by the column, so schema validation
     * asks the connection whether an ordered index leads with it. Where none
     * does, {@see indexed()} names the index the catalogue cannot show and
     * {@see unindexed()} records why the sort is affordable without one.
     *
     * @return self
     */
    public function sortable(): self
    {
        $this->sortable = true;

        return $this;
    }

    /**
     * Name the index that backs this field's sortable column.
     *
     * The connection is the authority on which index leads with a column, so
     * this exists only for what reading the catalogue cannot show: an index
     * over an expression, or one whose predicate the catalogue reports apart
     * from its columns. Validation looks the index up by name, so naming one
     * the table does not carry is a defect; what it covers is not read back,
     * since that is the part the catalogue cannot describe, so the override
     * vouches for the column rather than proving it.
     *
     * @param  string  $index
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public function indexed(string $index): self
    {
        if (trim($index) === '') {
            throw new \InvalidArgumentException('An index declaration must name an index');
        }

        $this->indexed = trim($index);

        return $this;
    }

    /**
     * Exempt this field's sortable column from needing an index, recording why.
     *
     * The reason is required so an exemption is never silent: it is the whole
     * artefact a reviewer has to weigh a sort that reads the table against.
     *
     * @param  string  $reason
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public function unindexed(string $reason): self
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An index exemption must carry a reason');
        }

        $this->unindexed = trim($reason);

        return $this;
    }

    /**
     * Declare this field's column as searchable with the given match strategy.
     *
     * The strategy is explicit because it decides which index has to back the
     * column: an omitted one would either pick the cheapest match, quietly
     * narrowing what the client asked for, or the broadest, quietly asking the
     * connection for an index nobody declared.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return self
     */
    public function searchable(SearchStrategy $strategy): self
    {
        $this->searchable = $strategy;

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
        $key = $this->alias ?? $this->name;

        return [
            $key => array_filter([
                'accessor'   => $this->accessor,
                'compute'    => $this->compute,
                'filterable' => $this->filterable !== null ? $this->name : null,
                'capability' => $this->filterable,
                'sortable'   => $this->sortable ? $this->name : null,
                'indexed'    => $this->indexed,
                'unindexed'  => $this->unindexed,
                'searchable' => $this->searchable !== null ? $this->name : null,
                'strategy'   => $this->searchable,
                'extras'     => $this->extras ?: null,
                'needs'      => $this->needs ?: null,
                ...$this->commonAttributes(),
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * Merge multiple field definitions into a single normalized array.
     *
     * @param  array<string, array<string, mixed>>|\Illuminate\Contracts\Support\Arrayable<string, array<string, mixed>>  ...$definitions
     * @return array<string, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException
     */
    public static function set(array|Arrayable ...$definitions): array
    {
        $compiled = [];

        foreach ($definitions as $definition) {

            $definition = $definition instanceof Arrayable ? $definition->toArray() : $definition;

            foreach ($definition as $key => $value) {

                if (array_key_exists($key, $compiled)) {
                    throw new DuplicateSchemaKeyException(sprintf('Duplicate schema key "%s" detected in Field::set()', $key));
                }

                $compiled[$key] = $value;
            }
        }

        return $compiled;
    }
}
