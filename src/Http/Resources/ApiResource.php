<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Config;
use LogicException;
use SensitiveParameter;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Traits\OrdersFields;

/**
 * The base API resource.
 *
 * Thin orchestrator that delegates field resolution, value resolution, guard
 * evaluation, schema compilation, and eager-load planning to dedicated
 * collaborator classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ApiResource extends JsonResource implements ApiResourceInterface
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
     * @param  mixed  $loadMissing
     * @param  array<int, string>|string|null  $included
     * @param  array<int, string>|null  $excluded
     */
    public function __construct(mixed $resource, mixed $loadMissing = false, array|string|null $included = null, ?array $excluded = null)
    {
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

        if ($loadMissing === true && is_object($resource)) {
            $this->loadMissingRelations($resource);
        }
    }

    /**
     * Resolve the resource to an array.
     *
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    public function resolve(#[SensitiveParameter] $request = null): array
    {
        $schema = SchemaCompiler::compile(static::class);
        $data   = ['_type' => static::getResourceType()];
        $fields = $this->fieldResolver->getFields(
            $schema,
            static::getResourceType(),
            static::getDefaultFields(),
            $this->getFixedFields(),
        );

        foreach ($fields as $field) {

            $definition = $schema->getField($field);

            if ($definition === null) {
                continue;
            }

            $value = $this->valueResolver->resolveFieldValue($field, $definition, $this, $request);

            if (!($value instanceof MissingValue)) {
                $data[$field] = $value;
            }
        }

        if ($this->fieldResolver->shouldIncludeCountsField(static::getResourceType(), static::getDefaultFields())) {

            $counts = $this->valueResolver->resolveCountsPayload($this, $schema, static::getResourceType(), $request);

            if ($counts !== []) {
                $data['counts'] = $counts;
            }
        }

        return $this->orderResolvedFields($data);
    }

    /**
     * Build a with()-ready eager-load map for the provided fields, including
     * constrained relations.
     *
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
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
    public static function eagerLoadCountsFor(?array $requestedAliases = null): array
    {
        return EagerLoadPlanner::buildCountMap(static::class, $requestedAliases);
    }

    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string
    {
        if (!defined(static::class . '::RESOURCE_TYPE')) {
            throw new LogicException('The RESOURCE_TYPE constant must be defined on the resource');
        }

        return strtolower(static::RESOURCE_TYPE); // @phpstan-ignore classConstant.notFound
    }

    /**
     * Get the default fields for this resource.
     *
     * @return array<int, string>
     */
    public static function getDefaultFields(): array
    {
        return static::$default;
    }

    /**
     * Get all non-metric field keys for this resource.
     *
     * @return array<int, string>
     */
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
    abstract public static function schema(): array;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resolve($request);
    }

    /**
     * Resolve the active fields from the API query or defaults.
     *
     * @return array<int, string>
     */
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
    protected static function newCollection(#[SensitiveParameter] $resource): ApiResourceCollection
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

        if (method_exists($resource, 'loadCount') && $this->fieldResolver->shouldIncludeCountsField(static::getResourceType(), static::getDefaultFields())) {

            $requestedCounts = ApiQuery::getCounts(static::getResourceType()) ?? [];
            $withCounts      = EagerLoadPlanner::buildCountMap(static::class, $requestedCounts);

            if ($withCounts !== []) {
                $resource->loadCount($withCounts);
            }
        }
    }

    /**
     * Get the fields that should always be included in the response.
     *
     * @return array<int, string>
     */
    private function getFixedFields(): array
    {
        /** @var array<int, string> */
        return array_merge(Config::get('api-toolkit.resources.fixed_fields', []), $this->fixed);
    }
}
