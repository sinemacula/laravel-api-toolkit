<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Renders the query surface as an auto-generated Markdown section.
 *
 * Opens with the bounds every request is held to, whatever it asks of whatever
 * resource: the cost and page-size bounds, the free-text term bounds, and the
 * shape of the rejection an over-budget request is answered with. The rest is
 * one table per resource naming the columns that answer a filter, an order, or
 * a search, so a client can read what a resource accepts without reading the
 * resource. It is rendered per audience, since what a resource may be asked is
 * the same disclosure its schema is and belongs only where that schema does.
 *
 * Resources are grouped by the module they belong to, so a modular application
 * reads one section per module beneath an optional shared "Common" section. A
 * flat application resolves every resource to no module, collapsing to one
 * subsection per resource as the degenerate case of the same grouping.
 * Resources are ordered by component name, their columns keep schema
 * declaration order, and the section opens with a machine-generated banner, so
 * the rendered output is stable and byte-identical between runs.
 *
 * The limits table is driven by the bounds the request-time guards actually
 * resolve rather than by a fixed row list, so a bound gained later is reported
 * with its value even before it is described here, and the worked rejection
 * beneath it reads its numbers from the same resolved bounds so the example
 * cannot contradict the table above it.
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

    /** The fixed note saying what an index-backed order does and does not claim. */
    private const string ORDERS = 'An order reads as Index-backed where the resource recorded no exemption for it; the index itself is proven by '
        . 'schema validation, which reads the catalogue behind the model where a connection can be inspected.';

    /** The heading for resources that belong to no module. */
    private const string COMMON = 'Common';

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
        QueryCostLimits::MAX_PARSE_DEPTH   => 'The object levels the filter document nests.',
        QueryCostLimits::MAX_DEPTH         => 'The levels a filter descends, counting a logical group or a relation as one.',
        QueryCostLimits::MAX_NODES         => 'The keys a filter visits in total.',
        QueryCostLimits::MAX_IN_ITEMS      => 'The items a single operator value list carries, such as the one `$in` reads.',
        QueryCostLimits::MAX_ORDER_KEYS    => 'The columns one request may order by.',
        QueryCostLimits::MAX_AGGREGATES    => 'The relation counts, sums, and averages one request may ask for, combined.',
        QueryCostLimits::MAX_OFFSET        => 'The page number a paginated read may start at.',
        QueryParameterValidator::MAX_LIMIT => 'The records one page may carry, asked for with `limit`.',
    ];

    /** @var array<string, string> How each search term bound reads, keyed by bound name. */
    private const array BOUNDS = [
        'min_word_length' => 'Shortest word, in characters',
        'max_length'      => 'Longest term, in characters',
        'max_words'       => 'Most whitespace-separated words',
    ];

    /**
     * Create a new query surface documentation generator.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver  $resolver
     * @return void
     */
    public function __construct(

        /** Resolves the module each resource belongs to. */
        private ModuleResolver $resolver,
    ) {}

    /**
     * Render the query surface section as Markdown for the given resource
     * surfaces, request bounds, and search term bounds.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @param  array<string, int>  $limits
     * @param  array<string, int>  $bounds
     * @return string
     */
    public function generate(array $surfaces, array $limits, array $bounds): string
    {
        $lines = [self::BANNER, '', '# Query Surface Reference', '', self::INTRO, '', self::KEYS, self::ALIASES, '', self::ORDERS];

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
     * Render the shape an over-budget request is answered with, reading the
     * status and code from the exception that raises it and the worked numbers
     * from the same resolved bounds the table above reports.
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
     * Render one section per resource, grouped by module where the application
     * is modular and flat where it is not.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @return list<string>
     */
    private function resourceSections(array $surfaces): array
    {
        $sections = $this->sections($surfaces);
        $lines    = [];

        if ($this->isCombined($sections)) {

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
     * Group the surfaces into an ordered list of sections, the shared section
     * first followed by one section per module sorted by name.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @return list<array{heading: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>}>
     */
    private function sections(array $surfaces): array
    {
        $common  = [];
        $modules = [];

        foreach ($surfaces as $surface) {

            $module = $this->resolver->resolve($surface->resource);

            if ($module === null) {
                $common[] = $surface;
                continue;
            }

            $modules[$module->key] ??= ['name' => $module->name, 'items' => []];
            $modules[$module->key]['items'][] = $surface;
        }

        return $this->orderSections($common, $modules);
    }

    /**
     * Assemble the ordered section list, sorting the shared bucket and each
     * module's resources by component name and the modules by name.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $common
     * @param  array<string, array{name: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>}>  $modules
     * @return list<array{heading: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>}>
     */
    private function orderSections(array $common, array $modules): array
    {
        usort($common, $this->compareByComponentName(...));
        uasort($modules, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $sections = [];

        if ($common !== []) {
            $sections[] = ['heading' => self::COMMON, 'items' => $common];
        }

        foreach ($modules as $module) {

            $items = $module['items'];

            usort($items, $this->compareByComponentName(...));

            $sections[] = ['heading' => $module['name'], 'items' => $items];
        }

        return $sections;
    }

    /**
     * Determine whether the sections collapse to the flat per-resource output,
     * which holds when nothing is grouped under a module.
     *
     * @param  list<array{heading: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>}>  $sections
     * @return bool
     */
    private function isCombined(array $sections): bool
    {
        return $sections === []
            || (count($sections) === 1 && $sections[0]['heading'] === self::COMMON);
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
     * Render a resource's columns as a table, or the line saying it answers no
     * query at all.
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
            $column->capability === null ? self::NO_VALUE : $this->code($column->capability->value),
            $this->operators($column),
            $this->order($column),
            $column->strategy === null ? self::NO_VALUE : $this->code($column->strategy->value),
        );
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
     * Render whether the column may be ordered by and whether the resource
     * recorded an exemption from index backing, carrying the reason where it
     * did.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor  $column
     * @return string
     */
    private function order(QueryColumnDescriptor $column): string
    {
        if (!$column->sortable) {
            return self::NO_VALUE;
        }

        return $column->unindexedReason === null
            ? self::INDEXED
            : self::UNINDEXED . $this->escape($column->unindexedReason);
    }

    /**
     * Render the relations a filter may descend through, saying so plainly
     * where the resource declares none.
     *
     * @param  array<int, string>  $relations
     * @return string
     */
    private function relationsLine(array $relations): string
    {
        if ($relations === []) {
            return 'Traversable relations: none.';
        }

        return 'Traversable relations: ' . implode(', ', array_map($this->code(...), $relations)) . '.';
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
