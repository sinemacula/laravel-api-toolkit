<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Database\Eloquent\SoftDeletes;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Builds the shared components.parameters set once and names which of them each
 * REST action accepts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryParameterBuilder
{
    /** The path prefix under which parameter components are referenced */
    private const string PARAMETER_REF_PREFIX = '#/components/parameters/';

    /** The component names shaping the representation of a serialised resource */
    private const array SHAPING_PARAMETERS = ['Fields', 'Counts', 'Sums', 'Averages'];

    /** The component names selecting, ordering, and paging a collection */
    private const array SELECTION_PARAMETERS = ['Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination'];

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
            'Filters'    => $this->buildFiltersParameter(),
            'Search'     => $this->buildSearchParameter(),
            'Order'      => $this->buildOrderParameter(),
            'Limit'      => $this->buildLimitParameter(),
            'Page'       => $this->buildPageParameter(),
            'Cursor'     => $this->buildCursorParameter(),
            'Pagination' => $this->buildPaginationParameter(),
            'Counts'     => $this->buildCountsParameter(),
            'Sums'       => $this->buildSumsParameter(),
            'Averages'   => $this->buildAveragesParameter(),
            'Trashed'    => $this->buildTrashedParameter(),
        ];
    }

    /**
     * List the parameter references the given REST action accepts, in order.
     *
     * Soft-delete visibility is offered only where the model soft deletes.
     *
     * @param  string  $action
     * @param  class-string|null  $modelClass
     * @return array<int, array<string, string>>
     */
    public function referencesFor(string $action, ?string $modelClass = null): array
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

        return array_map(
            static fn (string $name): array => ['$ref' => self::PARAMETER_REF_PREFIX . $name],
            $names,
        );
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
            'Sparse fieldsets: restrict the attributes returned per resource type, e.g. fields[users]=id,name.',
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'deepObject',
        );
    }

    /**
     * Build the generic filter parameter documenting the operator vocabulary.
     *
     * The parser accepts only a URL-encoded JSON object, so the schema is a
     * JSON-carrying string.
     *
     * @return array<string, mixed>
     */
    private function buildFiltersParameter(): array
    {
        $operators = $this->operatorVocabulary();

        return $this->parameter(
            'filters',
            sprintf(
                'Generic filter grammar. Filters are a URL-encoded JSON object keyed by field and combined with the operator vocabulary: %s. '
                . 'A whole document is sent under the one key, e.g. filters={"status":{"$eq":"active"}}. '
                . 'The operator grammar is documented at the pattern level; each resource accepts only the fields it declares filterable.',
                implode(', ', $operators),
            ),
            [
                'type'             => 'string',
                'contentMediaType' => 'application/json',
                'x-operators'      => $operators,
            ],
        );
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
     * Build the ordering parameter.
     *
     * @return array<string, mixed>
     */
    private function buildOrderParameter(): array
    {
        return $this->parameter(
            'order',
            'Ordering: a comma-separated list of fields, each optionally suffixed with :desc, e.g. order=name,created_at:desc.',
            ['type' => 'string'],
        );
    }

    /**
     * Build the page-size limit parameter, publishing any configured ceiling.
     *
     * @return array<string, mixed>
     */
    private function buildLimitParameter(): array
    {
        $ceiling = $this->catalogue->getQueryLimits()[QueryParameterValidator::MAX_LIMIT] ?? 0;
        $schema  = ['type' => 'integer', 'minimum' => 1];

        if ($ceiling > 0) {
            $schema['maximum'] = $ceiling;
        }

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
            'Opaque cursor token for cursor pagination.',
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
            'Pagination mode: set to cursor to switch from the default length-aware pagination to cursor pagination.',
            ['type' => 'string', 'enum' => ['cursor']],
        );
    }

    /**
     * Build the relation-counts parameter.
     *
     * @return array<string, mixed>
     */
    private function buildCountsParameter(): array
    {
        return $this->parameter(
            'counts',
            'Relation counts: request count keys per resource type, e.g. counts[users]=posts.',
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'deepObject',
        );
    }

    /**
     * Build the relation-sums parameter.
     *
     * @return array<string, mixed>
     */
    private function buildSumsParameter(): array
    {
        return $this->parameter(
            'sums',
            'Relation sums: request sum aggregates per resource type and relation, e.g. sums[users][posts]=id.',
            ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
            'deepObject',
        );
    }

    /**
     * Build the relation-averages parameter.
     *
     * @return array<string, mixed>
     */
    private function buildAveragesParameter(): array
    {
        return $this->parameter(
            'averages',
            'Relation averages: request average aggregates per resource type and relation, e.g. averages[users][posts]=id.',
            ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
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
            . 'e.g. trashed=with. Omitting it returns live records only. A resource that has not opted in to exposing its soft-deleted records ignores it.',
            ['type' => 'string', 'enum' => ['with', 'only']],
        );
    }

    /**
     * Assemble the registered and structural operator vocabulary.
     *
     * @return array<int, string>
     */
    private function operatorVocabulary(): array
    {
        return array_merge(
            $this->catalogue->getOperatorTokens(),
            $this->catalogue->getStructuralOperators(),
        );
    }

    /**
     * Build a single query parameter component descriptor.
     *
     * @param  string  $name
     * @param  string  $description
     * @param  array<string, mixed>  $schema
     * @param  string|null  $style
     * @return array<string, mixed>
     */
    private function parameter(string $name, string $description, array $schema, ?string $style = null): array
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
            $parameter['explode'] = true;
        }

        return $parameter;
    }
}
