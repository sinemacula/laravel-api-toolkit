<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;

/**
 * Builds the shared components.parameters set once.
 *
 * Emits the toolkit's query-parameter grammar as reusable components: sparse
 * fieldsets, the generic filter grammar (documenting the full operator
 * vocabulary at the pattern level, leaving the accepted fields to each
 * resource's own declarations), ordering, the pagination set (limit, page,
 * cursor, and the pagination-mode switch), and relation counts. Resource
 * components and the assembled document reference these by name; the
 * definitions are never duplicated per resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryParameterBuilder
{
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
            'Filter'     => $this->buildFilterParameter(),
            'Order'      => $this->buildOrderParameter(),
            'Limit'      => $this->buildLimitParameter(),
            'Page'       => $this->buildPageParameter(),
            'Cursor'     => $this->buildCursorParameter(),
            'Pagination' => $this->buildPaginationParameter(),
            'Counts'     => $this->buildCountsParameter(),
            'Sums'       => $this->buildSumsParameter(),
            'Averages'   => $this->buildAveragesParameter(),
        ];
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
     * Build the generic filter parameter documenting the full operator
     * vocabulary at the pattern level.
     *
     * The operator tokens enumerate both the registered comparison operators
     * and the structural operators, so consumers learn the grammar without the
     * exporter restating each resource's declared field set.
     *
     * @return array<string, mixed>
     */
    private function buildFilterParameter(): array
    {
        $operators = $this->operatorVocabulary();

        return $this->parameter(
            'filter',
            sprintf(
                'Generic filter grammar. Filters are keyed by field and combined with the operator vocabulary: %s. '
                . 'The operator grammar is documented at the pattern level; each resource accepts only the fields it declares filterable.',
                implode(', ', $operators),
            ),
            [
                'type'                 => 'object',
                'additionalProperties' => true,
                'x-operators'          => $operators,
            ],
            'deepObject',
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
     * Build the page-size limit parameter.
     *
     * @return array<string, mixed>
     */
    private function buildLimitParameter(): array
    {
        return $this->parameter(
            'limit',
            'Page size: the maximum number of records to return per page.',
            ['type' => 'integer', 'minimum' => 1],
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
     * The toolkit paginates length-aware by default and switches to cursor
     * pagination when this parameter is set to `cursor` (or when a `cursor`
     * token is supplied), so `cursor` is the only value that changes behaviour.
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
     * Assemble the full operator vocabulary (registered tokens followed by the
     * structural operators).
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
