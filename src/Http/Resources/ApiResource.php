<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use SineMacula\ApiToolkit\Concerns\OrdersFields;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * The base API resource.
 *
 * Thin orchestrator that delegates field resolution, value resolution, guard
 * evaluation, schema compilation, and eager-load planning to dedicated
 * collaborator classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
abstract class ApiResource extends ToolkitResource implements ApiResourceInterface
{
    use OrdersFields;

    /** @var array<int, string> Default fields to include if no specific fields are requested */
    protected static array $default = [];

    /** @var array<int, string> Fixed fields to include in the response */
    protected array $fixed = [];

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver */
    private readonly FieldResolver $fieldResolver;

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver */
    private readonly ValueResolver $valueResolver;

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
        $schema = SchemaCompiler::compile(static::class);
        $data   = ['_type' => static::getResourceType()];
        $fields = $this->fieldResolver->getFields(
            $schema,
            static::getResourceType(),
            static::getDefaultFields(),
            $this->fixed,
        );

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
        }

        $this->appendMetricPayloads($data, $schema, $request);

        return $this->orderResolvedFields($data);
    }

    /**
     * Build a with()-ready eager-load map for the provided fields, including
     * constrained relations.
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
     * Build a withCount-ready array for this resource.
     *
     * @param  array<int, string>|null  $requestedAliases
     * @return array<int|string, mixed>
     */
    #[\Override]
    public static function eagerLoadCountsFor(?array $requestedAliases = null): array
    {
        return EagerLoadPlanner::buildCountMap(static::class, $requestedAliases);
    }

    /**
     * Build a withSum-ready entry list for this resource.
     *
     * @param  array<string, mixed>|null  $requestedSums
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public static function eagerLoadSumsFor(?array $requestedSums = null): array
    {
        return EagerLoadPlanner::buildSumMap(static::class, $requestedSums);
    }

    /**
     * Build a withAvg-ready entry list for this resource.
     *
     * @param  array<string, mixed>|null  $requestedAverages
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public static function eagerLoadAveragesFor(?array $requestedAverages = null): array
    {
        return EagerLoadPlanner::buildAvgMap(static::class, $requestedAverages);
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
     * Load missing relations and counts on the underlying resource.
     *
     * @param  object  $resource
     * @return void
     */
    private function loadMissingRelations(object $resource): void
    {
        if (method_exists($resource, 'loadMissing')) {

            $fields = $this->fieldResolver->shouldRespondWithAll(static::getResourceType())
                ? static::getAllFields()
                : static::resolveFields();

            $with = EagerLoadPlanner::buildEagerLoadMap(static::class, $fields);

            if ($with !== []) {
                $resource->loadMissing($with);
            }
        }

        $requestedCounts = ApiQuery::getCounts(static::getResourceType()) ?? [];

        if (method_exists($resource, 'loadCount') && $this->fieldResolver->shouldIncludeCountsField(static::getResourceType(), static::getDefaultFields())) {
            $withCounts = $this->rejectLoadedAggregates(
                $resource,
                EagerLoadPlanner::buildCountMap(static::class, $requestedCounts),
            );

            if ($withCounts !== []) {
                $resource->loadCount($withCounts);
            }
        }

        $this->loadMissingAggregates($resource);
    }

    /**
     * Append counts, sums, and averages payloads to the resolved data array.
     *
     * @param  array<string, mixed>  $data
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  mixed  $request
     * @return void
     */
    private function appendMetricPayloads(array &$data, CompiledSchema $schema, mixed $request): void
    {
        if ($this->fieldResolver->shouldIncludeCountsField(static::getResourceType(), static::getDefaultFields())) {
            $counts = $this->valueResolver->resolveCountsPayload($this, $schema, static::getResourceType(), $request);

            if ($counts !== []) {
                $data['counts'] = $counts;
            }
        }

        if ($this->fieldResolver->shouldIncludeSumsField(static::getResourceType(), static::getDefaultFields())) {
            $sums = $this->valueResolver->resolveAggregatesPayload('sum', $this, $schema, static::getResourceType(), $request);

            if ($sums !== []) {
                $data['sums'] = $sums;
            }
        }

        if (!$this->fieldResolver->shouldIncludeAveragesField(static::getResourceType(), static::getDefaultFields())) {
            return;
        }

        $averages = $this->valueResolver->resolveAggregatesPayload('avg', $this, $schema, static::getResourceType(), $request);

        if ($averages === []) {
            return;
        }

        $data['averages'] = $averages;
    }

    /**
     * Load missing sum and average aggregates on the resource.
     *
     * @param  object  $resource
     * @return void
     */
    private function loadMissingAggregates(object $resource): void
    {
        $resourceType  = static::getResourceType();
        $defaultFields = static::getDefaultFields();

        if (method_exists($resource, 'loadSum') && $this->fieldResolver->shouldIncludeSumsField($resourceType, $defaultFields)) {
            $sums = $this->rejectLoadedAggregates($resource, EagerLoadPlanner::buildSumMap(static::class, ApiQuery::getSums($resourceType) ?? []));

            foreach ($sums as $entry) {
                // @var array<string, mixed> $entry
                $resource->loadSum($entry['relation'], $entry['column']); // @phpstan-ignore argument.type
            }
        }

        if (!method_exists($resource, 'loadAvg') || !$this->fieldResolver->shouldIncludeAveragesField($resourceType, $defaultFields)) {
            return;
        }

        $averages = $this->rejectLoadedAggregates($resource, EagerLoadPlanner::buildAvgMap(static::class, ApiQuery::getAverages($resourceType) ?? []));

        foreach ($averages as $entry) {
            // @var array<string, mixed> $entry
            $resource->loadAvg($entry['relation'], $entry['column']); // @phpstan-ignore argument.type
        }
    }

    /**
     * Reject aggregate specifications already loaded on the resource.
     *
     * The criteria pre-loads counts and aggregates on the base query, so a
     * per-row load would re-run them; unlike loadMissing() for relations, the
     * load{Count,Sum,Avg} helpers are not idempotent, so filter them here. The
     * spec is read from a count entry (string, or aliased key with a closure)
     * or from an aggregate entry's relation.
     *
     * @param  object  $resource
     * @param  array<int|string, mixed>  $specifications
     * @return array<int|string, mixed>
     */
    private function rejectLoadedAggregates(object $resource, array $specifications): array
    {
        return array_filter($specifications, static function ($value, $key) use ($resource): bool {

            $spec = $value;

            if (is_array($value) && isset($value['relation'])) {
                $spec = $value['relation'];
            } elseif (is_string($key)) {
                $spec = $key;
            }

            return !EagerLoadPlanner::isAggregateAttributeLoaded($resource, $spec);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
