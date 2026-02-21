<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\BuildsApiResourceSchema;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ResolvesApiResourceValues;
use SineMacula\ApiToolkit\Traits\OrdersFields;

/**
 * The base API resource.
 *
 * Handles dynamic field filtering and eager loading based on API query
 * parameters and the resource schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class ApiResource extends BaseResource implements ApiResourceInterface
{
    use BuildsApiResourceSchema;
    use OrdersFields;
    use ResolvesApiResourceValues;

    /** @var array<int, string> Default fields to include if no specific fields are requested */
    protected static array $default = [];

    /** @var array<int, string> Fixed fields to include in the response */
    protected array $fixed = [];

    /** @var array<class-string, array<string, array<string, mixed>>> Compiled schema cache keyed by resource class */
    private static array $schemaCache = [];

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @param  mixed  $load_missing
     * @param  array<int, string>|string|null  $included
     * @param  array<int, string>|null  $excluded
     */
    public function __construct(mixed $resource, mixed $load_missing = false, array|string|null $included = null, ?array $excluded = null)
    {
        parent::__construct($resource);

        if ($included === ':all') {
            $this->withAll();
        }

        if (is_array($included)) {
            $this->withFields($included);
        }

        if ($excluded !== null) {
            $this->withoutFields($excluded);
        }

        if ($load_missing === true && is_object($resource)) {
            $this->loadMissingRelations($resource);
        }
    }

    /**
     * Resolve the resource to an array.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
     *
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    public function resolve(#[\SensitiveParameter] mixed $request = null): array
    {
        // phpcs:enable
        $data   = ['_type' => static::getResourceType()];
        $fields = $this->getFields();

        foreach ($fields as $field) {
            $value = $this->resolveFieldValue($field, $request);

            if (!($value instanceof MissingValue)) {
                $data[$field] = $value;
            }
        }

        if ($this->shouldIncludeCountsField()) {
            $counts = $this->resolveCountsPayload();

            if ($counts !== []) {
                $data['counts'] = $counts;
            }
        }

        return $this->orderResolvedFields($data);
    }

    /**
     * Build a withCount-ready array for this resource.
     *
     * @param  array<int, string>|null  $requested_aliases
     * @return array<int, string>|array<string, \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void>
     */
    public static function eagerLoadCountsFor(?array $requested_aliases = null): array
    {
        $with = [];

        foreach (self::countDefinitions() as $present_key => $definition) {
            if (!self::shouldIncludeCount($present_key, $requested_aliases, $definition)) {
                continue;
            }

            $relation   = $definition['relation'];
            $constraint = $definition['constraint'] ?? null;

            if ($constraint instanceof \Closure) {
                $with[$relation] = $constraint;
                continue;
            }

            $with[] = $relation;
        }

        return $with;
    }

    /**
     * Build a with()-ready eager-load map for the provided fields.
     *
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    public static function eagerLoadMapFor(array $fields): array
    {
        $plain   = [];
        $scoped  = [];
        $visited = [];

        self::walkRelationsWith(static::class, $fields, '', $plain, $scoped, $visited);

        if ($plain === [] && $scoped === []) {
            return [];
        }

        return array_merge($plain, $scoped);
    }

    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string
    {
        $resource_class = static::class;

        if (!defined($resource_class . '::RESOURCE_TYPE')) {
            throw new \LogicException('The RESOURCE_TYPE constant must be defined on the resource');
        }

        $resource_type = constant($resource_class . '::RESOURCE_TYPE');

        if (!is_string($resource_type) || $resource_type === '') {
            throw new \LogicException('The RESOURCE_TYPE constant must be a non-empty string');
        }

        return strtolower($resource_type);
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
     * Get the full schema-backed field list for this resource.
     *
     * @return array<int, string>
     */
    public static function getAllFields(): array
    {
        $schema = static::getCompiledSchema();

        return array_values(
            array_filter(
                array_keys($schema),
                static fn (string $key): bool => ($schema[$key]['metric'] ?? null) === null,
            ),
        );
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
     * Resolve requested field names from query or defaults.
     *
     * @return array<int, string>
     */
    public static function resolveFields(): array
    {
        return ApiQuery::getFields(static::getResourceType()) ?? static::getDefaultFields();
    }

    /**
     * Resolve "counts" payload using already-loaded "{$relation}_count" attributes.
     *
     * @return array<string, int>
     */
    protected function resolveCountsPayload(): array
    {
        $owner = $this->unwrapResource($this->resource);

        if (!is_object($owner)) {
            return [];
        }

        $requested = ApiQuery::getCounts(static::getResourceType()) ?? [];
        $result    = [];

        foreach (self::countDefinitions() as $present_key => $definition) {
            if (!self::shouldIncludeCount($present_key, $requested, $definition) || !$this->passesGuards($definition, null)) {
                continue;
            }

            $value = $this->getAttributeIfLoaded($owner, $definition['relation'] . '_count');

            if (is_int($value)) {
                $result[$present_key] = $value;
                continue;
            }

            if (is_float($value) || is_bool($value) || (is_string($value) && is_numeric($value))) {
                $result[$present_key] = (int) $value;
            }
        }

        return $result;
    }

    /**
     * Get the compiled schema for this resource class.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function getCompiledSchema(): array
    {
        $class = static::class;

        if (isset(self::$schemaCache[$class])) {
            return self::$schemaCache[$class];
        }

        return self::$schemaCache[$class] = $class::schema();
    }

    /**
     * Resolve requested fields and enforce strict allow-listing.
     *
     * @return array<int, string>
     */
    protected function getFields(): array
    {
        $this->fields ??= $this->shouldRespondWithAll()
            ? static::getAllFields()
            : static::resolveFields();

        $allowed  = $this->getAllowedFields();
        $resolved = array_values(array_intersect($this->fields, $allowed));
        $resolved = array_diff($resolved, $this->excludedFields ?? []);
        $merged   = array_merge($resolved, array_intersect($this->getFixedFields(), $allowed));

        return array_values(array_unique($merged));
    }

    /**
     * Create a new resource collection instance.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    protected static function newCollection(#[\SensitiveParameter] mixed $resource): ApiResourceCollection
    {
        // phpcs:enable
        return new ApiResourceCollection($resource, static::class);
    }

    /**
     * Determines whether all fields should be included in the response.
     *
     * @return bool
     */
    private function shouldRespondWithAll(): bool
    {
        return $this->all || in_array(':all', ApiQuery::getFields(self::getResourceType()) ?? [], true);
    }

    /**
     * Gets fields that should always be included.
     *
     * @return array<int, string>
     */
    private function getFixedFields(): array
    {
        $configured_fixed = Config::get('api-toolkit.resources.fixed_fields', []);

        if (!is_array($configured_fixed)) {
            $configured_fixed = [];
        }

        $normalized = array_values(
            array_filter(
                array_map(
                    static fn (mixed $field): ?string => is_string($field) && $field !== '' ? $field : null,
                    array_merge($configured_fixed, $this->fixed),
                ),
                static fn (?string $field): bool => $field !== null,
            ),
        );

        return array_values(array_unique($normalized));
    }

    /**
     * Get the full allow-list of fields that can be resolved.
     *
     * @return array<int, string>
     */
    private function getAllowedFields(): array
    {
        return array_values(
            array_unique(
                array_merge(
                    static::getAllFields(),
                    $this->getFixedFields(),
                    ['counts'],
                ),
            ),
        );
    }

    /**
     * Determine if counts should be included in the resource.
     *
     * @return bool
     */
    private function shouldIncludeCountsField(): bool
    {
        $requested_fields = ApiQuery::getFields(static::getResourceType());
        $excluded_fields  = $this->excludedFields ?? [];

        if (is_array($requested_fields) && in_array('counts', $requested_fields, true)) {
            return !in_array('counts', $excluded_fields, true);
        }

        if ($this->shouldRespondWithAll() || (is_array($requested_fields) && in_array(':all', $requested_fields, true))) {
            return !in_array('counts', $excluded_fields, true);
        }

        return in_array('counts', static::getDefaultFields(), true) && !in_array('counts', $excluded_fields, true);
    }
}
