<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Renders the query surface as an auto-generated Markdown section.
 *
 * The limits table is driven by the resolved bounds, so a new bound is reported
 * before it is described.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurfaceDocGenerator
{
    /** The banner marking the file as regenerated on every run. */
    private const string BANNER = '<!-- This file is auto-generated. Do not edit; regenerating the documentation overwrites it. -->';

    /** The fixed introduction printed beneath the heading. */
    private const string INTRO = 'What each resource may be filtered, ordered, and searched by, and the bounds a single request is held to.';

    /** The fixed note naming the two name columns of a resource table. */
    private const string KEYS = 'In each resource table, Field is the property the response carries and Key is the name to send in `filters` and `order`, '
        . 'and the column a `search` matches on.';

    /** The fixed note saying when those two names come apart. */
    private const string ALIASES = 'The two differ wherever a field is presented under an alias.';

    /** The fixed note saying what an index-backed order claims and how pages stay whole. */
    private const string ORDERS = 'An order reads as Index-backed where the resource recorded no exemption for it; the index itself is proven by '
        . 'schema validation, which reads the catalogue behind the model where a connection can be inspected. Every page is ordered by '
        . 'the key last, in the last direction, so tied rows never repeat or go missing; a cursor-paginated read refuses random order '
        . 'and any column that admits null.';

    /** The fixed note saying what the relevance order ranks by and needs. */
    private const string RELEVANCE = '`order=relevance` ranks rows by how closely they match `search`: the whole value, then the start, then the '
        . 'start of a word, then anywhere; it needs `search` and offset pagination.';

    /** The fixed note saying who may use a conditional key. */
    private const string CONDITIONAL = 'A filter or order marked conditional is open only to callers the resource shows the field to; any other caller '
        . 'is refused as for an undeclared key.';

    /** The suffix marking a filter or order only some callers may use. */
    private const string CONDITIONAL_SUFFIX = ' (conditional)';

    /** The cell shown where a column answers nothing, or a row is undescribed. */
    private const string NO_VALUE = '-';

    /** The value shown for a limit that is configured off. */
    private const string DISABLED = 'Disabled';

    /** The order cell shown where an index holds the order. */
    private const string INDEXED = 'Index-backed';

    /** The order cell prefix shown where the resource exempted the column. */
    private const string UNINDEXED = 'Unindexed: ';

    /** @var array<string, string> What each request bound holds, keyed by its name. */
    private const array LIMITS = [
        QueryCostLimits::MAX_BYTES         => 'The byte length of the filter document.',
        QueryCostLimits::MAX_PARSE_DEPTH   => 'The levels the filter document nests, objects and lists alike.',
        QueryCostLimits::MAX_DEPTH         => 'The levels a filter descends, counting a logical group or a relation as one.',
        QueryCostLimits::MAX_NODES         => 'The keys a filter visits in total.',
        QueryCostLimits::MAX_IN_ITEMS      => 'The items a single operator value list carries, such as the one `$in` reads.',
        QueryCostLimits::MAX_ORDER_KEYS    => 'The columns one request may order by.',
        QueryCostLimits::MAX_AGGREGATES    => 'The relation aggregates one request may ask for, across every resource type.',
        QueryCostLimits::MAX_OFFSET        => 'The rows a paginated read may scan past to reach its page, and so how far its total counts.',
        QueryParameterValidator::MAX_LIMIT => 'The records one page may carry, asked for with `limit`.',
    ];

    /** @var array<string, string> How each search term bound reads, keyed by bound name. */
    private const array BOUNDS = [
        SearchTerm::MIN_WORD_LENGTH_KEY => 'Shortest word, in characters',
        SearchTerm::MAX_LENGTH_KEY      => 'Longest term, in characters',
        SearchTerm::MAX_WORDS_KEY       => 'Most whitespace-separated words',
    ];

    /**
     * Create a new query surface documentation generator.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\ModuleSectionGrouper  $grouper
     * @return void
     */
    public function __construct(

        /** Groups the resources into the sections the reference renders. */
        private ModuleSectionGrouper $grouper,
    ) {}

    /**
     * Render the query surface section as Markdown.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @param  array<string, int>  $limits
     * @param  array<string, int>  $bounds
     * @return string
     */
    public function generate(array $surfaces, array $limits, array $bounds): string
    {
        $lines = [self::BANNER, '', '# Query Surface Reference', '', self::INTRO, '', self::KEYS, self::ALIASES, '', self::ORDERS, '', self::RELEVANCE, '', self::CONDITIONAL];

        $lines = array_merge(
            $lines,
            $this->limitsSection($limits),
            $this->boundsSection($bounds),
            $this->rejectionSection($limits),
            $this->resourceSections($surfaces),
        );

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render the bounds a single request is held to.
     *
     * @param  array<string, int>  $limits
     * @return list<string>
     */
    private function limitsSection(array $limits): array
    {
        $lines = [
            '',
            '## Request Limits',
            '',
            'These bound the cost of one request. A request exceeding any of them is rejected before any SQL is issued, and a limit shown as Disabled is not enforced.',
            '',
            '| Limit | Value | Bounds |',
            '| --- | --- | --- |',
        ];

        foreach ($limits as $cap => $value) {
            $lines[] = sprintf(
                '| %s | %s | %s |',
                $this->code($cap),
                $value > 0 ? $value : self::DISABLED,
                self::LIMITS[$cap] ?? self::NO_VALUE,
            );
        }

        return $lines;
    }

    /**
     * Render the bounds a free-text search term is held to.
     *
     * @param  array<string, int>  $bounds
     * @return list<string>
     */
    private function boundsSection(array $bounds): array
    {
        $lines = [
            '',
            '## Search Term Bounds',
            '',
            'A term outside these bounds is refused as a validation failure on the `search` parameter, never trimmed to fit.',
            '',
            '| Bound | Value |',
            '| --- | --- |',
        ];

        foreach ($bounds as $bound => $value) {
            $lines[] = sprintf('| %s | %d |', self::BOUNDS[$bound] ?? $this->escape($bound), $value);
        }

        return $lines;
    }

    /**
     * Render the shape an over-budget request is answered with.
     *
     * @param  array<string, int>  $limits
     * @return list<string>
     */
    private function rejectionSection(array $limits): array
    {
        $limit = $limits[QueryCostLimits::MAX_IN_ITEMS] ?? 0;

        return [
            '',
            '## Over-Budget Rejection',
            '',
            'A request over one of these limits is answered with the standard error envelope, whose `meta` names the limit,',
            'the parameter at fault, the position within it, and the value supplied:',
            '',
            '```json',
            '{',
            '  "error": {',
            sprintf('    "status": %d,', QueryTooExpensiveException::getHttpStatusCode()),
            sprintf('    "code": %d,', QueryTooExpensiveException::getInternalErrorCode()),
            '    "meta": {',
            '      "parameter": "filters",',
            '      "pointer": "/posts/title/$in",',
            sprintf('      "reason": "%s",', QueryCostLimits::MAX_IN_ITEMS),
            sprintf('      "limit": %d,', $limit),
            sprintf('      "actual": %d', $limit + 1),
            '    }',
            '  }',
            '}',
            '```',
            '',
            'The `reason` names the limit as the table above spells it, and the `pointer` is empty where a limit bounds',
            'a parameter as a whole rather than a position within it. The title and detail the envelope carries',
            'alongside are the ones the error catalogue lists for this code.',
        ];
    }

    /**
     * Render one section per resource, grouped by module.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @return list<string>
     */
    private function resourceSections(array $surfaces): array
    {
        $sections = $this->grouper->group(
            $surfaces,
            static fn (QuerySurfaceDescriptor $surface): string => $surface->resource,
            $this->compareByComponentName(...),
        );

        $lines = [];

        if ($this->grouper->isCombined($sections)) {

            foreach ($sections[0]['items'] ?? [] as $surface) {
                $lines = array_merge($lines, $this->resourceSection($surface, '##'));
            }

            return $lines;
        }

        foreach ($sections as $section) {
            $lines[] = '';
            $lines[] = '## ' . $section['heading'];

            foreach ($section['items'] as $surface) {
                $lines = array_merge($lines, $this->resourceSection($surface, '###'));
            }
        }

        return $lines;
    }

    /**
     * Compare two surfaces by the component name of the resource behind them.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $a
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $b
     * @return int
     */
    private function compareByComponentName(QuerySurfaceDescriptor $a, QuerySurfaceDescriptor $b): int
    {
        return SchemaComponentName::fromResource($a->resource) <=> SchemaComponentName::fromResource($b->resource);
    }

    /**
     * Render one resource as a subsection heading, its column table, and the
     * relations a filter may descend through.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @param  string  $level
     * @return list<string>
     */
    private function resourceSection(QuerySurfaceDescriptor $surface, string $level): array
    {
        $lines = ['', $level . ' ' . SchemaComponentName::fromResource($surface->resource), ''];
        $lines = array_merge($lines, $this->columnTable($surface->columns));

        return array_merge($lines, ['', $this->relationsLine($surface->relations)]);
    }

    /**
     * Render a resource's columns as a table.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>  $columns
     * @return list<string>
     */
    private function columnTable(array $columns): array
    {
        if ($columns === []) {
            return ['This resource answers no filter, order, or search.'];
        }

        $lines = [
            '| Field | Key | Filter | Operators | Order | Search |',
            '| --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($columns as $column) {
            $lines[] = $this->columnRow($column);
        }

        return $lines;
    }

    /**
     * Render one column as a table row.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor  $column
     * @return string
     */
    private function columnRow(QueryColumnDescriptor $column): string
    {
        return sprintf(
            '| %s | %s | %s | %s | %s | %s |',
            $this->code($column->property),
            $this->code($column->column),
            $this->filter($column),
            $this->operators($column),
            $this->order($column),
            $column->strategy === null ? self::NO_VALUE : $this->code($column->strategy->value),
        );
    }

    /**
     * Render the capability the column answers a filter with.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor  $column
     * @return string
     */
    private function filter(QueryColumnDescriptor $column): string
    {
        if ($column->capability === null) {
            return self::NO_VALUE;
        }

        return $this->code($column->capability->value) . ($column->filterConditional ? self::CONDITIONAL_SUFFIX : '');
    }

    /**
     * Render the operator tokens the column answers a filter with.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor  $column
     * @return string
     */
    private function operators(QueryColumnDescriptor $column): string
    {
        if ($column->operators === []) {
            return self::NO_VALUE;
        }

        return implode(', ', array_map($this->code(...), $column->operators));
    }

    /**
     * Render whether the column may be ordered by, and any index exemption.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor  $column
     * @return string
     */
    private function order(QueryColumnDescriptor $column): string
    {
        if (!$column->sortable) {
            return self::NO_VALUE;
        }

        $order = $column->unindexedReason === null
            ? self::INDEXED
            : self::UNINDEXED . $this->escape($column->unindexedReason);

        return $order . ($column->sortConditional ? self::CONDITIONAL_SUFFIX : '');
    }

    /**
     * Render the relations a filter may descend through.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor>  $relations
     * @return string
     */
    private function relationsLine(array $relations): string
    {
        if ($relations === []) {
            return 'Traversable relations: none.';
        }

        return 'Traversable relations: ' . implode(', ', array_map($this->relation(...), $relations)) . '.';
    }

    /**
     * Render a relation with the resource governing the keys under it.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor  $relation
     * @return string
     */
    private function relation(QueryRelationDescriptor $relation): string
    {
        $governor = match (true) {
            $relation->polymorphic       => 'per type',
            $relation->resource === null => 'no nested filters',
            default                      => SchemaComponentName::fromResource($relation->resource),
        };

        return sprintf('%s (%s)', $this->code($relation->relation), $governor);
    }

    /**
     * Render a value the client sends or reads back as an escaped code span.
     *
     * @param  string  $value
     * @return string
     */
    private function code(string $value): string
    {
        return '`' . $this->escape($value) . '`';
    }

    /**
     * Escape a table cell so a pipe or line break cannot break the table.
     *
     * @param  string  $text
     * @return string
     */
    private function escape(string $text): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\|', ' ', ' ', ' '], $text);
    }
}
