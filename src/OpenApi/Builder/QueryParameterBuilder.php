<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Builds the shared components.parameters set once and lists the parameters
 * each REST action accepts.
 *
 * An index states its own filters and order inline, since the shared components
 * are not pruned per audience.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryParameterBuilder
{
    /** The path prefix under which parameter components are referenced */
    private const string PARAMETER_REF_PREFIX = '#/components/parameters/';

    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** The component names shaping the representation of a serialised resource */
    private const array SHAPING_PARAMETERS = ['Fields', 'Aggregates'];

    /** The name of the filter document, inlined per resource */
    private const string FILTERS_PARAMETER = 'Filters';

    /** The component name of the free-text search, offered where a field is searchable */
    private const string SEARCH_PARAMETER = 'Search';

    /** The name of the ordering, inlined per resource */
    private const string ORDER_PARAMETER = 'Order';

    /** The component name of the page size, inlined for a resource with its own */
    private const string LIMIT_PARAMETER = 'Limit';

    /** The names selecting, ordering, and paging a collection */
    private const array SELECTION_PARAMETERS = [self::FILTERS_PARAMETER, self::SEARCH_PARAMETER, self::ORDER_PARAMETER, self::LIMIT_PARAMETER, 'Page', 'Cursor', 'Pagination'];

    /** @var array<string, string> How each enabled cap on a filter document reads, keyed by cap name */
    private const array FILTER_CAPS = [
        QueryCostLimits::MAX_BYTES       => '%d bytes encoded',
        QueryCostLimits::MAX_PARSE_DEPTH => '%d levels of JSON nesting',
        QueryCostLimits::MAX_DEPTH       => '%d levels of groups and relations',
        QueryCostLimits::MAX_NODES       => '%d keys in all',
    ];

    /** The component name widening the soft-delete scope a read is served from */
    private const string VISIBILITY_PARAMETER = 'Trashed';

    /** The actions a soft-delete scope can widen */
    private const array READ_ACTIONS = ['index', 'show'];

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     */
    public function __construct(

        /** The catalogue providing query parameter metadata. */
        private MetadataCatalogue $catalogue,
    ) {}

    /**
     * Build the full components.parameters map, keyed by component name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function build(): array
    {
        return [
            'Fields'     => $this->buildFieldsParameter(),
            'Search'     => $this->buildSearchParameter(),
            'Limit'      => $this->buildLimitParameter($this->catalogue->getPageSize(null)),
            'Page'       => $this->buildPageParameter(),
            'Cursor'     => $this->buildCursorParameter(),
            'Pagination' => $this->buildPaginationParameter(),
            'Aggregates' => $this->buildAggregatesParameter(),
            'Trashed'    => $this->buildTrashedParameter(),
        ];
    }

    /**
     * List the parameters the given REST action accepts, in order.
     *
     * An index takes its own resource's filters and order, and search only
     * where a field is searchable. A resource whose page size bounds differ
     * from the configured ones gets its own limit parameter inline.
     *
     * @param  string  $action
     * @param  class-string|null  $modelClass
     * @param  string|null  $resourceClass
     * @return list<array<string, mixed>>
     */
    public function referencesFor(string $action, ?string $modelClass = null, ?string $resourceClass = null): array
    {
        $pageSize = $this->catalogue->getPageSize($resourceClass);
        $surface  = $action === 'index' ? $this->catalogue->getQuerySurface($resourceClass, $modelClass) : null;

        $resolved = [
            self::FILTERS_PARAMETER => $this->buildFiltersParameter($surface),
            self::SEARCH_PARAMETER  => $surface?->isSearchable() === true ? ['$ref' => self::PARAMETER_REF_PREFIX . self::SEARCH_PARAMETER] : null,
            self::ORDER_PARAMETER   => $this->buildOrderParameter($surface),
        ];

        if ($pageSize->differsFrom($this->catalogue->getPageSize(null))) {
            $resolved[self::LIMIT_PARAMETER] = $this->buildLimitParameter($pageSize);
        }

        $parameters = [];

        foreach ($this->namesFor($action, $modelClass) as $name) {
            $parameters[] = array_key_exists($name, $resolved) ? $resolved[$name] : ['$ref' => self::PARAMETER_REF_PREFIX . $name];
        }

        return array_values(array_filter($parameters, static fn (?array $parameter): bool => $parameter !== null));
    }

    /**
     * List the exceptions raised by the parameters the given REST action
     * accepts.
     *
     * @param  string  $action
     * @param  class-string|null  $modelClass
     * @param  string|null  $resourceClass
     * @return list<class-string<\Throwable>>
     */
    public function exceptionsFor(string $action, ?string $modelClass = null, ?string $resourceClass = null): array
    {
        $names = $this->namesFor($action, $modelClass);

        if ($names === []) {
            return [];
        }

        return array_intersect($names, self::SELECTION_PARAMETERS) !== [] && $this->capsQueryCost($resourceClass)
            ? [ValidationException::class, QueryTooExpensiveException::class]
            : [ValidationException::class];
    }

    /**
     * List the names of the parameters the given REST action may accept, in
     * order.
     *
     * Soft-delete visibility is offered only where the model soft deletes.
     *
     * @param  string  $action
     * @param  class-string|null  $modelClass
     * @return list<string>
     */
    private function namesFor(string $action, ?string $modelClass): array
    {
        $names = match ($action) {
            'index'           => [...self::SHAPING_PARAMETERS, ...self::SELECTION_PARAMETERS],
            'show'            => self::SHAPING_PARAMETERS,
            'store', 'update' => self::SHAPING_PARAMETERS,
            default           => [],
        };

        if (in_array($action, self::READ_ACTIONS, true) && $this->isSoftDeleting($modelClass)) {
            $names[] = self::VISIBILITY_PARAMETER;
        }

        return $names;
    }

    /**
     * Determine whether any query cost cap, or the resource's page size
     * ceiling, is enabled.
     *
     * A cap of zero or less is never enforced.
     *
     * @param  string|null  $resourceClass
     * @return bool
     */
    private function capsQueryCost(?string $resourceClass): bool
    {
        $caps = [...$this->catalogue->getQueryLimits(), QueryParameterValidator::MAX_LIMIT => $this->catalogue->getPageSize($resourceClass)->ceiling];

        return array_filter($caps, static fn (int $cap): bool => $cap > 0) !== [];
    }

    /**
     * Determine whether the model behind an operation soft deletes.
     *
     * @param  class-string|null  $modelClass
     * @return bool
     */
    private function isSoftDeleting(?string $modelClass): bool
    {
        if ($modelClass === null || !class_exists($modelClass)) {
            return false;
        }

        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }

    /**
     * Build the sparse-fieldset parameter.
     *
     * @return array<string, mixed>
     */
    private function buildFieldsParameter(): array
    {
        return $this->parameter(
            'fields',
            'Sparse fieldsets keyed by resource _type, e.g. fields[users]=id,name. Each value is a comma-separated '
            . 'list of that schema\'s property names, with aggregates selecting its aggregates payload, or :all to '
            . 'select every field. Unknown types and names are rejected. A type with no key selects its schema\'s '
            . 'x-default-fields, unless a parent relation pins its fields. A selected property may still be left out; '
            . 'only the required ones are always returned.',
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'deepObject',
        );
    }

    /**
     * Build the filter parameter of an index, typed by its resource's filter
     * document, or null when a filter may name nothing.
     *
     * The parser accepts only a URL-encoded JSON object, so the parameter
     * carries its schema as JSON content.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor|null  $surface
     * @return array<string, mixed>|null
     */
    private function buildFiltersParameter(?QuerySurfaceDescriptor $surface): ?array
    {
        if ($surface === null || !$surface->isFilterable()) {
            return null;
        }

        return [
            'name'        => 'filters',
            'in'          => 'query',
            'required'    => false,
            'description' => 'Filters: a JSON object, URL-encoded under the one key, e.g. filters={"status":{"$eq":"active"}}. '
                . 'The entries of a document AND together. A field takes a value, matched for equality, or an object of operators. '
                . '$and and $or take a non-empty list of filter documents, joined by AND or OR, '
                . 'e.g. filters={"status":"active","$or":[{"role":"admin"},{"role":"owner"}]}. '
                . 'A traversable relation key takes a filter document for the related resource. '
                . '$has and $hasnt take a relation name, a list of names, or relation names keyed to filter documents. '
                . '$scope invokes the named scopes the resource declares: a scope name, a list of names, or names keyed to objects of arguments, '
                . 'e.g. filters={"$scope":{"near":{"lat":51.5,"lng":-0.12,"km":5}}}. '
                . 'The schema lists the fields, operators and values this resource accepts; a date-time is sent in the form the field emits.'
                . $this->describeFilterCaps(),
            'content' => ['application/json' => ['schema' => ['$ref' => self::SCHEMA_REF_PREFIX . FilterSchemaBuilder::nameOf($surface->resource)]]],
        ];
    }

    /**
     * Describe the enabled caps a filter document is held to.
     *
     * @return string
     */
    private function describeFilterCaps(): string
    {
        $limits = $this->catalogue->getQueryLimits();
        $caps   = [];

        foreach (self::FILTER_CAPS as $cap => $format) {

            $limit = $limits[$cap] ?? 0;

            if ($limit <= 0) {
                continue;
            }

            $caps[] = sprintf($format, $limit);
        }

        return $caps === [] ? '' : ' A document is held to at most ' . implode(', ', $caps) . '.';
    }

    /**
     * Build the free-text search parameter, publishing the longest term.
     *
     * Unlike the page size, a zero bound refuses every term and is published.
     *
     * @return array<string, mixed>
     */
    private function buildSearchParameter(): array
    {
        $longest = $this->catalogue->getSearchBounds()[SearchTerm::MAX_LENGTH_KEY] ?? null;
        $schema  = ['type' => 'string'];

        if ($longest !== null && $longest >= 0) {
            $schema['maxLength'] = $longest;
        }

        return $this->parameter(
            'search',
            'Free-text search across the fields a resource declares searchable, e.g. search=John Smith. '
            . 'It matches the requested resource only and never traverses a relation; a term carrying a word shorter than the configured minimum is rejected, '
            . 'as is one longer, or carrying more words, than the configured bounds allow. '
            . 'The length is counted once surrounding and repeated whitespace has been collapsed, so a term written with more characters than the maximum may still be accepted.',
            $schema,
        );
    }

    /**
     * Build the ordering parameter of an index, listing the keys it accepts, or
     * null when it accepts none.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor|null  $surface
     * @return array<string, mixed>|null
     */
    private function buildOrderParameter(?QuerySurfaceDescriptor $surface): ?array
    {
        $keys = $surface === null ? [] : $this->orderKeys($surface);

        if ($keys === []) {
            return null;
        }

        $schema = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $keys]];
        $most   = $this->catalogue->getQueryLimits()[QueryCostLimits::MAX_ORDER_KEYS] ?? 0;

        if ($most > 0) {
            $schema['maxItems'] = $most;
        }

        return $this->parameter(
            'order',
            'Ordering: a comma-separated list of keys, each optionally suffixed with :asc or :desc in any case, e.g. order=name,created_at:desc. '
            . 'Any other direction, or a repeated key, is rejected. The key is appended last when the order lacks it, so tied rows never repeat or go missing '
            . 'between pages. Random order and columns that admit null cannot order a cursor-paginated read. order=relevance ranks rows by how '
            . 'closely they match search: the whole value, then the start, then the start of a word, then anywhere; it needs search and offset '
            . 'pagination. A sortable aggregate orders by aggregates.<key>, e.g. order=aggregates.posts_count:desc, and a sortable to-one '
            . 'relation by a sortable column of the resource it renders, e.g. order=customer.name; neither can order a cursor-paginated read, '
            . 'and a NULL value sorts where the database places NULL.',
            $schema,
            'form',
            false,
        );
    }

    /**
     * List the keys an order may name on the surface, each bare and with each
     * direction the server accepts on it.
     *
     * A column named like an order keyword is read as the keyword, so it is
     * left out.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @return list<string>
     */
    private function orderKeys(QuerySurfaceDescriptor $surface): array
    {
        $keys = [];

        foreach ($surface->columns as $column) {

            if (!$column->sortable || OrderApplier::isKeyword($column->column)) {
                continue;
            }

            array_push($keys, $column->column, $column->column . ':asc', $column->column . ':desc');
        }

        if ($surface->isSearchable()) {
            array_push($keys, OrderApplier::ORDER_BY_RELEVANCE, OrderApplier::ORDER_BY_RELEVANCE . ':asc');
        }

        if (QuerySettings::canOrderRandomly()) {
            $keys[] = OrderApplier::ORDER_BY_RANDOM;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Build the page-size limit parameter, publishing any ceiling and the size
     * an omitted limit is served at.
     *
     * @param  \SineMacula\ApiToolkit\Query\PageSize  $pageSize
     * @return array<string, mixed>
     */
    private function buildLimitParameter(PageSize $pageSize): array
    {
        $schema = ['type' => 'integer', 'minimum' => 1];

        if ($pageSize->ceiling > 0) {
            $schema['maximum'] = $pageSize->ceiling;
        }

        $schema['default'] = $pageSize->default;

        return $this->parameter(
            'limit',
            'Page size: the maximum number of records to return per page. A request above the ceiling is rejected rather than reduced to it.',
            $schema,
        );
    }

    /**
     * Build the page-number parameter.
     *
     * @return array<string, mixed>
     */
    private function buildPageParameter(): array
    {
        return $this->parameter(
            'page',
            'Page number for offset pagination.',
            ['type' => 'integer', 'minimum' => 1],
        );
    }

    /**
     * Build the cursor parameter.
     *
     * @return array<string, mixed>
     */
    private function buildCursorParameter(): array
    {
        return $this->parameter(
            'cursor',
            'Opaque cursor token for cursor pagination. A token that does not decode, or does not match the requested order, is rejected; '
            . 'restart from the first page.',
            ['type' => 'string'],
        );
    }

    /**
     * Build the pagination-mode parameter.
     *
     * Pagination is length-aware by default, so `cursor` is the only value that
     * changes it.
     *
     * @return array<string, mixed>
     */
    private function buildPaginationParameter(): array
    {
        return $this->parameter(
            'pagination',
            'Pagination mode: set to cursor to switch from the default length-aware pagination to cursor pagination. Any other non-empty value is rejected.',
            ['type' => 'string', 'enum' => ['cursor']],
        );
    }

    /**
     * Build the relation-aggregates parameter.
     *
     * @return array<string, mixed>
     */
    private function buildAggregatesParameter(): array
    {
        return $this->parameter(
            'aggregates',
            'Relation aggregates: request the keys of the resource\'s aggregates object per resource type, e.g. aggregates[users]=posts_count. '
            . 'Requesting any aggregate replaces the defaults of that type. A type\'s aggregates apply wherever it renders, at the root or '
            . 'embedded, when its fields select aggregates, except under a relation whose resource type varies per record. '
            . 'Unknown resource types and names are rejected.',
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'deepObject',
        );
    }

    /**
     * Build the soft-delete visibility parameter.
     *
     * @return array<string, mixed>
     */
    private function buildTrashedParameter(): array
    {
        return $this->parameter(
            'trashed',
            'Soft-delete visibility: with returns soft-deleted records alongside the live ones and only returns the soft-deleted records alone, '
            . 'e.g. trashed=with. Omitting it returns live records only. An included relation follows it where its resource opts in and otherwise '
            . 'stays live. A resource that has not opted in to exposing its soft-deleted records, or a caller it does not permit, gets a 422.',
            ['type' => 'string', 'enum' => ['with', 'only']],
        );
    }

    /**
     * Build a single query parameter component descriptor.
     *
     * @param  string  $name
     * @param  string  $description
     * @param  array<string, mixed>  $schema
     * @param  string|null  $style
     * @param  bool  $explode
     * @return array<string, mixed>
     */
    private function parameter(string $name, string $description, array $schema, ?string $style = null, bool $explode = true): array
    {
        $parameter = [
            'name'        => $name,
            'in'          => 'query',
            'required'    => false,
            'description' => $description,
            'schema'      => $schema,
        ];

        if ($style !== null) {
            $parameter['style']   = $style;
            $parameter['explode'] = $explode;
        }

        return $parameter;
    }
}
