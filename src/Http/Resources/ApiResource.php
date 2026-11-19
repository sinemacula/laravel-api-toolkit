<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use SineMacula\ApiToolkit\Concerns\OrdersFields;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GatesTrashedVisibility;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * The base API resource.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
abstract class ApiResource extends ToolkitResource implements ApiResourceInterface
{
    use GatesTrashedVisibility, OrdersFields;

    /** @var array<int, string> Default fields to include if no specific fields are requested */
    protected static array $default = [];

    /** @var array<int, string> Fields added to every selection, after the configured fixed fields */
    protected static array $fixed = [];

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver */
    private readonly FieldResolver $fieldResolver;

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver */
    private readonly ValueResolver $valueResolver;

    /** @var array{string, array<int, string>}|null The pivot accessor and the columns rendered from it */
    private ?array $pivot = null;

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @param  bool  $loadMissing
     * @param  array<int, string>|string|null  $included
     * @param  array<int, string>|null  $excluded
     */
    public function __construct(
        mixed $resource,
        bool $loadMissing = false,
        array|string|null $included = null,
        ?array $excluded = null,
    ) {
        parent::__construct($resource);

        $guardEvaluator      = new GuardEvaluator;
        $this->fieldResolver = new FieldResolver;
        $this->valueResolver = new ValueResolver($guardEvaluator);

        if ($included === ':all') {
            $this->fieldResolver->withAll();
        }

        if (is_array($included)) {
            $this->fieldResolver->withFields($included);
        }

        if ($excluded !== null) {
            $this->fieldResolver->withoutFields($excluded);
        }

        if ($loadMissing !== true || !is_object($resource)) {
            return;
        }

        $this->loadMissingRelations($resource);
    }

    /**
     * Resolve the resource to an array.
     *
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function resolve(#[\SensitiveParameter] mixed $request = null): array
    {
        $request = $request instanceof Request ? $request : null;
        $schema  = SchemaCompiler::compile(static::class);
        $data    = ['_type' => static::getResourceType()];
        $fields  = $this->fieldResolver->getFields(static::class);

        $truncated = [];

        foreach ($fields as $field) {

            $definition = $schema->getField($field);

            if ($definition === null) {
                continue;
            }

            $value = $this->valueResolver->resolveFieldValue($field, $definition, $this, $request);

            if ($value instanceof MissingValue) {
                continue;
            }

            $data[$field] = $value;

            if (!$this->valueResolver->wasTruncated()) {
                continue;
            }

            $truncated[] = $field;
        }

        if ($truncated !== []) {
            $data[RelationLimit::TRUNCATED_KEY] = $truncated;
        }

        if ($this->pivot !== null) {
            $this->appendPivotPayload($data, ...$this->pivot);
        }

        if (in_array('aggregates', $fields, true)) {
            $this->appendAggregatesPayload($data, $schema, $request);
        }

        return $this->orderResolvedFields($data);
    }

    /**
     * Build a with()-ready eager-load map for the given fields.
     *
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    #[\Override]
    public static function eagerLoadMapFor(array $fields): array
    {
        return EagerLoadPlanner::buildEagerLoadMap(static::class, $fields);
    }

    /**
     * Build a withAggregate-ready entry list for this resource.
     *
     * @param  array<int, string>|null  $requested
     * @return list<AggregateLoad>
     */
    #[\Override]
    public static function eagerLoadAggregatesFor(?array $requested = null): array
    {
        return EagerLoadPlanner::buildAggregateMap(static::class, $requested);
    }

    /**
     * Return the largest page size this resource serves, or null to keep the
     * configured ceiling.
     *
     * It can only lower the configured ceiling, never raise it.
     *
     * @return int|null
     */
    public static function maxLimit(): ?int
    {
        return null;
    }

    /**
     * Get the resource type.
     *
     * @return string
     *
     * @throws \LogicException
     */
    #[\Override]
    public static function getResourceType(): string
    {
        if (!defined(static::class . '::RESOURCE_TYPE')) {
            throw new \LogicException('The RESOURCE_TYPE constant must be defined on the resource');
        }

        return strtolower(static::RESOURCE_TYPE); // @phpstan-ignore classConstant.notFound
    }

    /**
     * Get the default fields for this resource.
     *
     * @return array<int, string>
     */
    #[\Override]
    public static function getDefaultFields(): array
    {
        return static::$default;
    }

    /**
     * Get the fields added to every selection: the configured fixed fields,
     * then the resource's own.
     *
     * @return array<int, string>
     */
    #[\Override]
    public static function getFixedFields(): array
    {
        return array_values(array_unique([...ResourceSettings::fixedFields(), ...static::$fixed]));
    }

    /**
     * Get all non-metric field keys for this resource.
     *
     * @return array<int, string>
     */
    #[\Override]
    public static function getAllFields(): array
    {
        $schema = SchemaCompiler::compile(static::class);

        return $schema->getFieldKeys();
    }

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    abstract public static function schema(): array;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return $this->resolve($request);
    }

    /**
     * Resolve the active fields from the API query or defaults.
     *
     * @return array<int, string>
     */
    #[\Override]
    public static function resolveFields(): array
    {
        return ApiQuery::getFields(static::getResourceType()) ?? static::getDefaultFields();
    }

    /**
     * Override the default fields and any requested fields.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    #[\Override]
    public function withFields(?array $fields = null): static
    {
        $this->fieldResolver->withFields($fields);

        return $this;
    }

    /**
     * Remove certain fields from the response.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    #[\Override]
    public function withoutFields(?array $fields = null): static
    {
        $this->fieldResolver->withoutFields($fields);

        return $this;
    }

    /**
     * Render the given columns of the pivot loaded under the accessor.
     *
     * Mutates this instance, as withFields() does.
     *
     * @param  string  $accessor
     * @param  array<int, string>  $columns
     * @return static
     */
    public function withPivot(string $accessor, array $columns): static
    {
        $this->pivot = [$accessor, $columns];

        return $this;
    }

    /**
     * Force the response to include all available fields.
     *
     * @return static
     */
    #[\Override]
    public function withAll(): static
    {
        $this->fieldResolver->withAll();

        return $this;
    }

    /**
     * Resolve the fields selected for this resource, before the fixed fields
     * join them.
     *
     * @return array<int, string>
     */
    protected function selectedFields(): array
    {
        return $this->fieldResolver->getSelectedFields(static::class);
    }

    /**
     * Create a new resource collection instance.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    #[\Override]
    protected static function newCollection(#[\SensitiveParameter] mixed $resource): ApiResourceCollection
    {
        return new ApiResourceCollection($resource, static::class);
    }

    /**
     * Load missing relations and aggregates on the underlying resource.
     *
     * @param  object  $resource
     * @return void
     */
    private function loadMissingRelations(object $resource): void
    {
        $fields = $this->fieldResolver->getFields(static::class);

        if (method_exists($resource, 'loadMissing')) {

            $with = EagerLoadPlanner::buildEagerLoadMap(static::class, $fields);

            if ($with !== []) {
                $resource->loadMissing($with);
            }
        }

        if (!in_array('aggregates', $fields, true)) {
            return;
        }

        $this->loadMissingAggregates($resource);
    }

    /**
     * Append the pivot payload to the resolved data array.
     *
     * @param  array<string, mixed>  $data
     * @param  string  $accessor
     * @param  array<int, string>  $columns
     * @return void
     */
    private function appendPivotPayload(array &$data, string $accessor, array $columns): void
    {
        $pivot = $this->valueResolver->resolvePivotPayload($this, $accessor, $columns);

        if ($pivot === []) {
            return;
        }

        $data['pivot'] = $pivot;
    }

    /**
     * Append the aggregates payload to the resolved data array.
     *
     * @param  array<string, mixed>  $data
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  \Illuminate\Http\Request|null  $request
     * @return void
     */
    private function appendAggregatesPayload(array &$data, CompiledSchema $schema, ?Request $request): void
    {
        $aggregates = $this->valueResolver->resolveAggregatesPayload($this, $schema, static::getResourceType(), $request);

        if ($aggregates === []) {
            return;
        }

        $data['aggregates'] = $aggregates;
    }

    /**
     * Load the selected aggregates the resource does not carry yet.
     *
     * The loadAggregate() helper is not idempotent, unlike loadMissing(). The
     * aggregates sharing a function and a column load in one query.
     *
     * @param  object  $resource
     * @return void
     */
    private function loadMissingAggregates(object $resource): void
    {
        if (!method_exists($resource, 'loadAggregate')) {
            return;
        }

        $groups = [];

        foreach (EagerLoadPlanner::buildAggregateMap(static::class, ApiQuery::getAggregates(static::getResourceType()) ?? []) as $entry) {

            if (EagerLoadPlanner::isAggregateAttributeLoaded($resource, $entry['relation'])) {
                continue;
            }

            $groups[$entry['function']][$entry['column']] = [...$groups[$entry['function']][$entry['column']] ?? [], ...(array) $entry['relation']];
        }

        foreach ($groups as $function => $columns) {
            foreach ($columns as $column => $relations) {
                $resource->loadAggregate($relations, $column, $function);
            }
        }
    }
}
